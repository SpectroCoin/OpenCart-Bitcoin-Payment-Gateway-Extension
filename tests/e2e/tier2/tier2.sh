#!/usr/bin/env bash
# ============================================================================
# Tier 2 end-to-end test — configure the extension, put a real OpenCart order
# through it, deliver callbacks, and assert what the shop actually does.
#
# Tier 1 proves the extension installs and loads. This proves it *works*: that
# the order we send SpectroCoin describes the shop's order, and that every
# status on the wire moves the shop's order where it should — or deliberately
# leaves it alone.
#
# The SpectroCoin API is stood in for by a stub answering as spectrocoin.com
# inside the compose network, over TLS signed by a CA generated here. No
# credentials, no live orders, no calls to the real API — and because the alias
# does the redirection, the extension's own Config URLs are exercised as they
# ship.
#
# The order is placed by driving the extension's own confirm() over HTTP with a
# real OpenCart session, so the payload is assembled by the extension itself
# rather than reproduced here.
#
# Usage:
#   ./tier2.sh          # run the full flow
#   ./tier2.sh --keep   # leave the stack running for inspection
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
CODE="spectrocoin"
KEEP=0

while [ $# -gt 0 ]; do
  case "$1" in
    --keep) KEEP=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILED=$((FAILED+1)); }
FAILED=0

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cd "$HERE"

# --------------------------------------------------------------------------
# 1. A CA and a certificate for spectrocoin.com.
# --------------------------------------------------------------------------
say "Generating certificates for the stub"
rm -rf .certs && mkdir -p .certs
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout .certs/ca.key -out .certs/ca.crt \
  -subj "/CN=SpectroCoin Tier2 Test CA" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout .certs/server.key -out .certs/server.csr \
  -subj "/CN=spectrocoin.com" >/dev/null 2>&1
printf 'subjectAltName=DNS:spectrocoin.com\n' > .certs/ext
openssl x509 -req -in .certs/server.csr -CA .certs/ca.crt -CAkey .certs/ca.key \
  -CAcreateserial -out .certs/server.crt -days 3650 -extfile .certs/ext >/dev/null 2>&1
chmod 644 .certs/*
[ -s .certs/server.crt ] && pass "issued a certificate for spectrocoin.com" \
  || fail "certificate generation failed"

# --------------------------------------------------------------------------
# 2. The stack.
# --------------------------------------------------------------------------
say "Starting OpenCart and the API stub"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1

oc()   { docker compose exec -T opencart "$@"; }
stub() { docker compose exec -T spectrocoin "$@"; }
q()    { docker compose exec -T db mariadb -uroot -proot -N -B opencart -e "$1" 2>/dev/null | tr -d '\r'; }

# Trust the test CA. Appended rather than replacing the bundle.
oc sh -c 'cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt' >/dev/null 2>&1 || true

# The store URL must be a dotted host: the extension builds its callbackUrl
# from it, and rejects a URL whose host has no dot.
oc php /var/www/html/install/cli_install.php install \
  --username admin --password tier2tier2 --email tier2@example.com \
  --http_server http://shop.test/ \
  --db_driver mysqli --db_hostname db --db_username root --db_password root \
  --db_database opencart --db_port 3306 --db_prefix oc_ \
  > "$WORK/install.log" 2>&1 || true

if [ "$(q 'SELECT COUNT(*) FROM oc_user;')" -ge 1 ] 2>/dev/null; then
  pass "OpenCart installed"
else
  fail "OpenCart install failed:"; sed 's/^/        /' "$WORK/install.log" | tail -8
fi

# --------------------------------------------------------------------------
# 3. The extension, packaged the way the release is.
# --------------------------------------------------------------------------
say "Installing and configuring the extension"
BUILD="$WORK/$CODE"
mkdir -p "$BUILD"
( cd "$ROOT" && find . -maxdepth 1 -not -path '.' -not -path './.git' \
    -not -path './.github' -not -path './tests' -not -path './.gitignore' \
    -exec cp -r {} "$BUILD/" \; )
( cd "$BUILD" && composer install --no-dev --prefer-dist --optimize-autoloader \
    --no-interaction -q 2>/dev/null || php "$ROOT/../composer.phar" install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction -q )

docker compose cp "$BUILD" "opencart:/var/www/html/extension/$CODE" >/dev/null 2>&1
oc sh -c "chown -R www-data:www-data /var/www/html/extension/$CODE" \
  && pass "extension unpacked into extension/$CODE" \
  || fail "extension could not be unpacked"

# Register the extension the way the admin installer does. OpenCart 4 only
# registers an extension's namespace for extensions recorded in these tables,
# so until this exists every extension/... route answers 404 - the files being
# in the right place is not enough.
q "SET SESSION sql_mode='';
   INSERT INTO oc_extension_install (extension_download_id, name, description, code,
     version, author, link, status, date_added)
   VALUES (0,'SpectroCoin','','$CODE','2.1.0','SpectroCoin','',1,NOW());
   INSERT INTO oc_extension (extension, type, code) VALUES ('$CODE','payment','$CODE');" >/dev/null 2>&1
registered=$(q "SELECT COUNT(*) FROM oc_extension WHERE code='$CODE';")
[ "${registered:-0}" -ge 1 ] && pass "extension registered with OpenCart" \
                            || fail "extension could not be registered - its routes will 404"

# Configure it as a merchant would through the admin settings screen.
q "INSERT INTO oc_setting (store_id, \`code\`, \`key\`, value, serialized) VALUES
     (0,'payment_spectrocoin','payment_spectrocoin_status','1',0),
     (0,'payment_spectrocoin','payment_spectrocoin_project','tier2-project',0),
     (0,'payment_spectrocoin','payment_spectrocoin_client_id','tier2-client',0),
     (0,'payment_spectrocoin','payment_spectrocoin_client_secret','tier2-secret',0);" >/dev/null 2>&1
configured=$(q "SELECT COUNT(*) FROM oc_setting WHERE \`code\`='payment_spectrocoin';")
[ "${configured:-0}" -ge 4 ] && pass "credentials configured" \
                             || fail "could not configure the extension"

# --------------------------------------------------------------------------
# 4. Place a real order and drive the extension's own confirm().
# --------------------------------------------------------------------------
say "Placing an order through the extension"
stub curl -fsS -X POST http://localhost/__test/reset >/dev/null 2>&1

CCY=$(q "SELECT value FROM oc_setting WHERE \`key\`='config_currency';")
CCY=${CCY:-USD}

# A real row in OpenCart's own order table.
#
# It starts at status 1 (confirmed), not 0. OpenCart treats an order at status 0
# as not yet placed, and makes the first addHistory() the confirmation step -
# which restocks products and needs the order_product/order_total rows a real
# checkout would have written. Starting confirmed keeps the callback on the
# plain status-change path, which is what is under test here.
#
# sql_mode is relaxed for this insert only: the table has NOT NULL columns with
# no default that a checkout would fill in, and none matter to the callback.
q "SET SESSION sql_mode='';
   INSERT INTO oc_order (invoice_prefix, store_id, store_name, store_url,
     customer_id, customer_group_id, firstname, lastname, email, telephone,
     payment_method, payment_firstname, payment_lastname, payment_country_id, payment_zone_id,
     shipping_method,
     total, order_status_id, currency_id, currency_code, currency_value,
     ip, date_added, date_modified)
   VALUES ('INV-2023-00', 0, 'tier2', 'http://shop.test/',
     0, 1, 'Tier', 'Two', 'tier2@example.com', '000',
     '{\"name\":\"SpectroCoin\",\"code\":\"spectrocoin.spectrocoin\"}', 'Tier', 'Two', 0, 0,
     '',
     61.80, 1, 1, '$CCY', 1.0,
     '127.0.0.1', NOW(), NOW());" >/dev/null 2>&1

OC_ORDER=$(q "SELECT order_id FROM oc_order ORDER BY order_id DESC LIMIT 1;")
if [ -n "$OC_ORDER" ] && [ "$OC_ORDER" -gt 0 ] 2>/dev/null; then
  pass "OpenCart order #$OC_ORDER created"
else
  fail "no order row could be created"
fi

# confirm() reads the order id out of the shopper's session, so give it one.
# OpenCart 4 keeps session data as JSON in its own table.
SESS=$(openssl rand -hex 16)
q "SET SESSION sql_mode='';
   INSERT INTO oc_session (session_id, data, expire)
   VALUES ('$SESS', '{\"order_id\":$OC_ORDER,\"language\":\"en-gb\",\"currency\":\"$CCY\"}',
           DATE_ADD(NOW(), INTERVAL 1 HOUR));" >/dev/null 2>&1

# Driven from the stub container, which is what resolves the shop's hostname.
shopcurl() { docker compose exec -T spectrocoin curl "$@"; }
shopcurl -s -o /dev/null -b "OCSESSID=$SESS" \
  "http://shop.test/index.php?route=extension/spectrocoin/payment/spectrocoin.confirm" || true

# --------------------------------------------------------------------------
# 5. What the extension actually sent us.
# --------------------------------------------------------------------------
say "Inspecting the request the extension sent"
stub curl -fsS http://localhost/__test/requests > "$WORK/requests.json" 2>/dev/null

created=$(python3 - "$WORK/requests.json" <<'PYEOF'
import json,sys
for r in json.load(open(sys.argv[1])):
    if r["path"].endswith("/orders/create"):
        print(json.dumps({**json.loads(r["body"] or "{}"), "_ua": r["user_agent"]}))
        break
PYEOF
)
[ -n "$created" ] || created='{}'
field() { printf '%s' "$created" | python3 -c "import json,sys;print(json.load(sys.stdin).get('$1',''))" 2>/dev/null; }

if [ -n "$(field orderId)" ]; then
  pass "an order was sent to SpectroCoin"
else
  fail "no create-order request reached SpectroCoin. OpenCart log:"
  oc sh -c 'tail -5 /var/www/html/system/storage/logs/*.log 2>/dev/null' | sed 's/^/        /' | head -8
fi

case "$(field orderId)" in
  "$OC_ORDER"-*) pass "orderId carries the shop's order id" ;;
  *) fail "orderId '$(field orderId)' does not start with $OC_ORDER-" ;;
esac

[ "$(field receiveCurrencyCode)" = "$CCY" ] \
  && pass "order was sent in the shop's currency ($CCY)" \
  || fail "receiveCurrencyCode was '$(field receiveCurrencyCode)', shop uses '$CCY'"

if python3 -c "
import sys
sys.exit(0 if abs(float('$(field receiveAmount)' or 'nan') - 61.80) < 0.005 else 1)" 2>/dev/null; then
  pass "order was sent for the shop's total (61.80)"
else
  fail "receiveAmount was '$(field receiveAmount)', order total is 61.80"
fi

case "$(field callbackUrl)" in
  *spectrocoin*callback*) pass "callbackUrl points at the extension's endpoint" ;;
  *) fail "unexpected callbackUrl: '$(field callbackUrl)'" ;;
esac

[ "$(field projectId)" = "tier2-project" ] \
  && pass "projectId is the configured one" \
  || fail "projectId was '$(field projectId)'"

case "$(field _ua)" in
  SpectroCoin-OpenCart/*) pass "identifies itself as $(field _ua)" ;;
  *) fail "User-Agent was '$(field _ua)', expected SpectroCoin-OpenCart/<version>" ;;
esac

UUID=$(stub sh -c 'php -r "\$s=json_decode(file_get_contents(\"/tmp/stub-state.json\"),true); echo array_key_first(\$s[\"orders\"]);"' 2>/dev/null)
[ -n "$UUID" ] && pass "SpectroCoin order created (uuid ${UUID:0:8}…)" \
               || fail "no SpectroCoin order was created"

# --------------------------------------------------------------------------
# 6. Deliver callbacks and assert what the shop does with each status.
# --------------------------------------------------------------------------
say "Delivering callbacks for every status on the wire"

CB="http://shop.test/index.php?route=extension/spectrocoin/payment/callback"

patch_order() {
  stub curl -fsS -X POST -H 'Content-Type: application/json' -d "$1" \
    http://localhost/__test/status >/dev/null 2>&1
}

# confirm() moved the order to status 1; that is the baseline each status is
# judged from.
BASE=$(q "SELECT order_status_id FROM oc_order WHERE order_id=$OC_ORDER;")
BASE=${BASE:-1}
reset_order() {
  q "UPDATE oc_order SET order_status_id=$BASE WHERE order_id=$OC_ORDER;" >/dev/null 2>&1
}

check_status() {
  local status="$1" want="$2" note="${3:-}"
  reset_order
  patch_order "{\"uuid\":\"$UUID\",\"status\":\"$status\"}"
  local code got
  code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-api\"}" "$CB")
  got=$(q "SELECT order_status_id FROM oc_order WHERE order_id=$OC_ORDER;")
  if [ "$code" = "200" ] && [ "$got" = "$want" ]; then
    pass "$status -> status $want${note:+ ($note)}"
  else
    fail "$status gave HTTP $code and status '$got', expected 200 and '$want'${note:+ ($note)}"
  fi
}

check_status NEW     "$BASE" "no change"
check_status PENDING 2       "processing"
check_status PAID    15      "processed"
check_status FAILED          7
check_status CANCELLED       7
check_status REJECTED        7
check_status INVALID_PAYMENT 7
check_status EXPIRED         14

# Informational statuses report on a payment already under way. The order must
# be left exactly as it was: transitioning here would either fulfil an order
# that was not paid in full, or reverse one the merchant already settled.
for s in PARTIAL_PAYMENT UNDERPAID LATE_CRYPTO_PAYMENT PENDING_LATE_CRYPTO_PAYMENT \
         PROCESSING_REFUND REFUNDED REJECTED_REFUND TEST TEST_PAID TEST_EXPIRED; do
  check_status "$s" "$BASE" "informational, no change"
done

# --------------------------------------------------------------------------
# 7. The callback endpoint is a public URL. It must refuse the obvious abuse.
# --------------------------------------------------------------------------
say "Callback endpoint guards"

code=$(shopcurl -s -o /dev/null -w '%{http_code}' "$CB")
[ "$code" = "405" ] && pass "GET is refused (405)" \
                    || fail "GET returned $code, expected 405 - the callback must be POST-only"

patch_order "{\"uuid\":\"$UUID\",\"orderId\":\"999999-aaaaaa\"}"
code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-api\"}" "$CB")
if [ "$code" = "404" ] || [ "$code" = "400" ]; then
  pass "a callback for an unknown order is refused ($code)"
else
  fail "unknown order returned $code, expected 404 or 400"
fi

# An order placed through a different payment method must not be settleable.
q "SET SESSION sql_mode='';
   INSERT INTO oc_order (invoice_prefix, store_id, store_name, store_url,
     customer_id, customer_group_id, firstname, lastname, email, telephone,
     payment_method, payment_firstname, payment_lastname, payment_country_id, payment_zone_id,
     shipping_method, total, order_status_id, currency_id, currency_code, currency_value,
     ip, date_added, date_modified)
   VALUES ('INV-2023-00', 0, 'tier2', 'http://shop.test/', 0, 1, 'Other', 'Gateway',
     'other@example.com', '000', '{\"name\":\"Cheque\",\"code\":\"cheque.cheque\"}',
     'Other', 'Gateway', 0, 0, '', 61.80, 1, 1, '$CCY', 1.0,
     '127.0.0.1', NOW(), NOW());" >/dev/null 2>&1
OTHER=$(q "SELECT order_id FROM oc_order ORDER BY order_id DESC LIMIT 1;")
patch_order "{\"uuid\":\"$UUID\",\"status\":\"PAID\",\"orderId\":\"$OTHER-aaaaaa\"}"
code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-api\"}" "$CB")
after=$(q "SELECT order_status_id FROM oc_order WHERE order_id=$OTHER;")
if [ "$code" = "400" ] && [ "$after" = "1" ]; then
  pass "a callback cannot settle an order paid by another method (400)"
else
  fail "callback returned $code and left order #$OTHER at status '$after'"
fi

# Restore the mapping, then disagree about the currency.
patch_order "{\"uuid\":\"$UUID\",\"orderId\":\"$OC_ORDER-aaaaaa\",\"receiveCurrencyCode\":\"XXX\",\"status\":\"PAID\"}"
reset_order
code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-api\"}" "$CB")
now=$(q "SELECT order_status_id FROM oc_order WHERE order_id=$OC_ORDER;")
if [ "$code" = "400" ] && [ "$now" = "$BASE" ]; then
  pass "a settlement in the wrong currency is refused (400)"
else
  fail "currency mismatch returned $code and left the order at status '$now'"
fi

# --------------------------------------------------------------------------
# 8. Nothing may have been logged as an error.
# --------------------------------------------------------------------------
say "OpenCart log"
log=$(oc sh -c 'cat /var/www/html/system/storage/logs/*.log 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "fatal|uncaught|parse error" || true)
[ -z "$ours" ] && pass "no fatals in the log" \
  || { fail "fatals in the log:"; printf '%s\n' "$ours" | head -6; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: add '127.0.0.1 shop.test' to /etc/hosts, then"
  echo    "http://shop.test:8088/admin (admin/tier2tier2)"
else
  docker compose down -v >/dev/null 2>&1 || true
  rm -rf .certs
fi

echo
[ "$FAILED" -eq 0 ] && echo "tier 2 PASSED" || echo "tier 2 FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
