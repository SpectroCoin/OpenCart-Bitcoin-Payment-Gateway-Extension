#!/usr/bin/env bash
# ============================================================================
# Tier 3 end-to-end test — a real shopper, in a real browser, through a real
# OpenCart checkout.
#
# Tier 1 proves the extension installs. Tier 2 drives the extension's own
# confirm() and every callback status directly, so it proves the gateway
# works - but never that a shopper can reach it. OpenCart 4's checkout is a
# single AJAX-driven page (shopper details -> shipping method -> payment
# method -> confirm), each step gated behind the one before it, so the only
# way to answer "can a shopper pay with this" is to walk one there.
#
# The SpectroCoin API is stood in for by a stub answering as spectrocoin.com
# inside the compose network, over TLS signed by a CA generated here. No
# credentials, no live orders, no calls to the real API - and because the alias
# does the redirection, the extension's own Config URLs are exercised as they
# ship.
#
# Usage:
#   ./tier3.sh          # run the full journey
#   ./tier3.sh --keep   # leave the stack running for inspection
#
# Screenshots of any failing step land in ./artifacts/.
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
rm -rf artifacts && mkdir -p artifacts

# --------------------------------------------------------------------------
# 1. A CA and a certificate for spectrocoin.com.
# --------------------------------------------------------------------------
say "Generating certificates for the stub"
rm -rf .certs && mkdir -p .certs
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout .certs/ca.key -out .certs/ca.crt \
  -subj "/CN=SpectroCoin Tier3 Test CA" >/dev/null 2>&1
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
say "Starting OpenCart, the API stub and a browser"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1

oc()   { docker compose exec -T opencart "$@"; }
stub() { docker compose exec -T spectrocoin "$@"; }
pw()   { docker compose exec -T playwright "$@"; }
q()    { docker compose exec -T db mariadb -uroot -proot -N -B opencart -e "$1" 2>/dev/null | tr -d '\r'; }

# Trust the test CA. Appended rather than replacing the bundle.
oc sh -c 'cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt' >/dev/null 2>&1 || true

# The store URL must be a dotted host: the extension builds its callbackUrl
# from it, and rejects a URL whose host has no dot.
oc php /var/www/html/install/cli_install.php install \
  --username admin --password tier3tier3 --email tier3@example.com \
  --http_server http://shop.test/ \
  --db_driver mysqli --db_hostname db --db_username root --db_password root \
  --db_database opencart --db_port 3306 --db_prefix oc_ \
  > "$WORK/install.log" 2>&1 || true

if [ "$(q 'SELECT COUNT(*) FROM oc_user;')" -ge 1 ] 2>/dev/null; then
  pass "OpenCart installed"
else
  fail "OpenCart install failed:"; sed 's/^/        /' "$WORK/install.log" | tail -8
fi

# The shop calls SpectroCoin over TLS, so it has to trust the CA this harness
# minted. Asserted rather than assumed: without it, checkout fails with
# "cURL error 60" and the only visible symptom is an order left unconfirmed.
if oc sh -c 'curl -fsS -o /dev/null https://spectrocoin.com/__test/requests' >/dev/null 2>&1; then
  pass "the shop trusts the stub's certificate"
else
  fail "the shop cannot reach the stub over TLS - checkout will fail"
fi

catalogue=$(q "SELECT COUNT(*) FROM oc_product;")
[ "${catalogue:-0}" -gt 0 ] && pass "demo catalogue has $catalogue products to buy" \
                            || fail "no products to buy - a shopper has nothing to check out with"

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
# so until this exists every extension/... route answers 404 - the files
# being in the right place is not enough.
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
     (0,'payment_spectrocoin','payment_spectrocoin_title','SpectroCoin',0),
     (0,'payment_spectrocoin','payment_spectrocoin_sort_order','1',0),
     (0,'payment_spectrocoin','payment_spectrocoin_project','tier3-project',0),
     (0,'payment_spectrocoin','payment_spectrocoin_client_id','tier3-client',0),
     (0,'payment_spectrocoin','payment_spectrocoin_client_secret','tier3-secret',0);" >/dev/null 2>&1
configured=$(q "SELECT COUNT(*) FROM oc_setting WHERE \`code\`='payment_spectrocoin';")
[ "${configured:-0}" -ge 6 ] && pass "extension configured and enabled" \
                             || fail "could not configure the extension"

# A stock OpenCart payment method, left enabled the way a fresh install ships
# it. This is the control for tier3.sh's decisive assertion: if it also fails
# to appear, an empty payment step is a fixture problem, not a finding about
# our extension. (Cash On Delivery and flat-rate shipping are both enabled
# by OpenCart's own installer, so nothing further is needed here - this just
# documents and verifies that fact rather than assuming it.)
cod_status=$(q "SELECT value FROM oc_setting WHERE \`key\`='payment_cod_status';")
flat_status=$(q "SELECT value FROM oc_setting WHERE \`key\`='shipping_flat_status';")
if [ "${cod_status:-0}" = "1" ] && [ "${flat_status:-0}" = "1" ]; then
  pass "stock payment method (Cash On Delivery) and flat-rate shipping are enabled"
else
  fail "stock payment method or shipping is not enabled - cod=$cod_status flat=$flat_status"
fi

stub curl -fsS -X POST http://localhost/__test/reset >/dev/null 2>&1

# --------------------------------------------------------------------------
# 4. Walk a shopper through checkout.
# --------------------------------------------------------------------------
say "Walking a shopper through checkout"
PRODUCT_URL="http://shop.test/index.php?route=product/product&product_id=40"

# The image carries the browsers but not the client library; pin it to the
# image's own version so the two cannot drift apart.
pw sh -c 'cd /work && [ -d node_modules/playwright ] || npm --silent i playwright@1.50.0' \
  > "$WORK/npm.log" 2>&1 || true
pw sh -c 'node -e "require(\"playwright\")"' >/dev/null 2>&1 \
  && pass "browser client available" \
  || { fail "playwright module could not be installed:"; tail -4 "$WORK/npm.log" | sed 's/^/        /'; }

pw sh -c "SHOP_URL=http://shop.test PRODUCT_URL='$PRODUCT_URL' EXT_TITLE='SpectroCoin' STOCK_TITLE='Cash On Delivery' node /work/checkout.mjs" \
  > "$WORK/browser.log" 2>&1 || true

# A browser run that produces no verdicts at all is a failure in itself, not
# a silent pass - and `set -o pipefail` would otherwise abort the script here.
if ! grep -aqE '^(PASS|FAIL)' "$WORK/browser.log"; then
  fail "the browser run produced no verdicts:"
  tail -12 "$WORK/browser.log" | sed 's/^/        /'
fi

grep -aE '^(PASS|FAIL|INFO)' "$WORK/browser.log" 2>/dev/null | while read -r line; do
  case "$line" in
    PASS*) printf '  \033[32mPASS\033[0m  %s\n' "${line#PASS }" ;;
    FAIL*) printf '  \033[31mFAIL\033[0m  %s\n' "${line#FAIL }" ;;
    INFO*) printf '  \033[33mNOTE\033[0m  %s\n' "${line#INFO }" ;;
  esac
done
browser_failures=$(grep -ac '^FAIL' "$WORK/browser.log" 2>/dev/null || true)
browser_failures=${browser_failures:-0}
FAILED=$((FAILED + browser_failures))
if [ "$browser_failures" -gt 0 ]; then
  echo "        --- browser log tail ---"
  tail -20 "$WORK/browser.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 5. What the shop and SpectroCoin ended up with.
# --------------------------------------------------------------------------
say "Verifying the order that resulted"
stub curl -fsS http://localhost/__test/requests > "$WORK/requests.json" 2>/dev/null

created=$(python3 - "$WORK/requests.json" <<'PYEOF'
import json,sys
for r in json.load(open(sys.argv[1])):
    if r["path"].endswith("/orders/create"):
        print(json.dumps(json.loads(r["body"] or "{}")))
        break
PYEOF
)
[ -n "$created" ] || created='{}'
field() { printf '%s' "$created" | python3 -c "import json,sys;print(json.load(sys.stdin).get('$1',''))" 2>/dev/null; }

if [ -n "$(field orderId)" ]; then
  pass "checkout produced a SpectroCoin order ($(field orderId))"
else
  fail "checkout never reached SpectroCoin - no create-order request arrived. OpenCart log:"
  oc sh -c 'tail -5 /var/www/html/system/storage/logs/*.log 2>/dev/null' | sed 's/^/        /' | head -8
fi

# The order OpenCart itself ended up with - not a literal, since the shop
# computes the total (product price + flat-rate shipping + tax) rather than
# the harness dictating it.
OC_ORDER=$(q "SELECT order_id FROM oc_order ORDER BY order_id DESC LIMIT 1;")
oc_total=$(q "SELECT total FROM oc_order WHERE order_id='$OC_ORDER';")
oc_currency=$(q "SELECT currency_code FROM oc_order WHERE order_id='$OC_ORDER';")
oc_status=$(q "SELECT order_status_id FROM oc_order WHERE order_id='$OC_ORDER';")
oc_payment=$(q "SELECT payment_method FROM oc_order WHERE order_id='$OC_ORDER';")

case "$(field orderId)" in
  "$OC_ORDER"-*) pass "orderId carries the shop's order id ($OC_ORDER)" ;;
  *) fail "orderId '$(field orderId)' does not start with $OC_ORDER-" ;;
esac

if [ -n "$oc_total" ] && python3 -c "
import sys
sys.exit(0 if abs(float('$(field receiveAmount)' or 'nan') - float('$oc_total')) < 0.005 else 1)" 2>/dev/null; then
  pass "order was sent for the shop's total ($oc_total)"
else
  fail "receiveAmount was '$(field receiveAmount)', shop's order total is '$oc_total'"
fi

[ "$(field receiveCurrencyCode)" = "$oc_currency" ] \
  && pass "order was sent in the shop's currency ($oc_currency)" \
  || fail "receiveCurrencyCode was '$(field receiveCurrencyCode)', shop uses '$oc_currency'"

case "$oc_payment" in
  *spectrocoin*) pass "the shop recorded the order against the SpectroCoin payment method" ;;
  *) fail "the shop's order payment_method was '$oc_payment', expected spectrocoin" ;;
esac

[ "$oc_status" = "1" ] \
  && pass "the shop moved the order to Pending after confirmation ($oc_status)" \
  || fail "the shop's order is at status '$oc_status', expected 1 (Pending)"

# --------------------------------------------------------------------------
# 6. OpenCart log.
# --------------------------------------------------------------------------
say "OpenCart log"
log=$(oc sh -c 'cat /var/www/html/system/storage/logs/*.log 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "fatal|uncaught|parse error" || true)
[ -z "$ours" ] && pass "no fatals in the log" \
  || { fail "fatals in the log:"; printf '%s\n' "$ours" | head -6; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: add '127.0.0.1 shop.test' to /etc/hosts, then"
  echo    "http://shop.test:8095/admin (admin/tier3tier3)"
else
  docker compose down -v >/dev/null 2>&1 || true
  rm -rf .certs
fi

echo
[ "$FAILED" -eq 0 ] && echo "tier 3 PASSED" || echo "tier 3 FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
