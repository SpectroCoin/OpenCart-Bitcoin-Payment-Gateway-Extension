#!/usr/bin/env bash
# ============================================================================
# Tier 1 smoke test — install the packaged extension into a real OpenCart and
# prove it actually runs.
#
# Catches what unit tests cannot: an artifact shipped without its vendor tree,
# an autoloader that does not resolve, an extension OpenCart cannot discover,
# or a controller that fatals when PHP actually loads it.
#
# OpenCart publishes no Docker image, so tests/e2e/Dockerfile builds one from
# the official release.
#
# Usage:
#   ./smoke.sh                    # package the working tree the way release.yml does
#   ./smoke.sh --artifact x.zip   # test an arbitrary zip (e.g. a CI artifact)
#   ./smoke.sh --keep             # leave the stack running for inspection
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
CODE="spectrocoin"
ARTIFACT=""
KEEP=0

while [ $# -gt 0 ]; do
  case "$1" in
    --artifact) ARTIFACT="${2:-}"; shift 2 ;;
    --keep)     KEEP=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILED=$((FAILED+1)); }
FAILED=0

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --------------------------------------------------------------------------
# 1. Obtain the artifact a merchant would install.
# --------------------------------------------------------------------------
say "Packaging artifact"
if [ -n "$ARTIFACT" ]; then
  cp "$ARTIFACT" "$WORK/ext.ocmod.zip"
  echo "  using supplied artifact $ARTIFACT"
else
  # Mirror release.yml exactly.
  ( cd "$ROOT" && zip -qr "$WORK/ext.ocmod.zip" . \
      -x '.git*' -x '.github*' -x 'README.txt' -x 'README.md' \
      -x 'changelog.md' -x '.gitignore' -x '.vscode' -x 'tests*' )
  echo "  built from working tree"
fi

unzip -qo "$WORK/ext.ocmod.zip" -d "$WORK/inspect"
[ -f "$WORK/inspect/vendor/autoload.php" ] \
  && pass "artifact contains vendor/autoload.php" \
  || fail "artifact has NO vendor/autoload.php - the extension cannot run"

guzzle=$(find "$WORK/inspect/vendor/guzzlehttp/guzzle/src" -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
if [ -f "$WORK/inspect/vendor/guzzlehttp/guzzle/src/Client.php" ] && [ "$guzzle" -gt 10 ]; then
  pass "artifact contains the HTTP client source ($guzzle files)"
else
  fail "artifact ships an EMPTY or partial guzzle tree ($guzzle php files)"
fi

for d in admin catalog system; do
  [ -d "$WORK/inspect/$d" ] && pass "artifact contains $d/" || fail "artifact is missing $d/"
done
[ -f "$WORK/inspect/install.json" ] \
  && pass "artifact contains install.json" || fail "artifact is missing install.json"

# --------------------------------------------------------------------------
# 2. Real OpenCart.
# --------------------------------------------------------------------------
say "Starting OpenCart (first run builds the image from the official release)"
cd "$HERE"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1
oc() { docker compose exec -T opencart "$@"; }
q()  { docker compose exec -T db mariadb -uroot -proot -N -B opencart -e "$1" 2>/dev/null | tr -d '\r'; }

oc php /var/www/html/install/cli_install.php install \
  --username admin --password smokesmoke1 --email smoke@example.com \
  --http_server http://localhost:8082/ \
  --db_driver mysqli --db_hostname db --db_username root --db_password root \
  --db_database opencart --db_port 3306 --db_prefix oc_ \
  > "$WORK/install.log" 2>&1 || true

if [ "$(q 'SELECT COUNT(*) FROM oc_user;')" -ge 1 ] 2>/dev/null; then
  pass "OpenCart installed"
else
  fail "OpenCart install failed:"; sed 's/^/        /' "$WORK/install.log" | tail -8
fi

# --------------------------------------------------------------------------
# 3. Install the artifact exactly as a merchant would.
# --------------------------------------------------------------------------
say "Installing the extension"
docker compose cp "$WORK/ext.ocmod.zip" opencart:/tmp/ext.zip >/dev/null
oc sh -c "rm -rf /var/www/html/extension/$CODE && mkdir -p /var/www/html/extension/$CODE \
          && unzip -qo /tmp/ext.zip -d /var/www/html/extension/$CODE \
          && chown -R www-data:www-data /var/www/html/extension/$CODE" \
  && pass "extension unpacked into extension/$CODE" \
  || fail "extension could not be unpacked"

# OpenCart discovers payment extensions by scanning this exact path.
found=$(oc sh -c "ls /var/www/html/extension/$CODE/admin/controller/payment/*.php 2>/dev/null | wc -l" | tr -d ' \r')
[ "${found:-0}" -ge 1 ] \
  && pass "OpenCart can discover the payment extension ($found controller(s))" \
  || fail "no admin payment controller where OpenCart looks - it would never appear in the extension list"

# --------------------------------------------------------------------------
# 4. Assertions that only a real install can make.
# --------------------------------------------------------------------------
say "Verifying inside the running shop"

if oc php -r "
  require '/var/www/html/extension/$CODE/vendor/autoload.php';
  exit(class_exists('GuzzleHttp\\\\Client') ? 0 : 1);" >/dev/null 2>&1; then
  pass "GuzzleHttp\\Client resolves via autoload"
else
  fail "GuzzleHttp\\Client does NOT resolve - vendor tree is absent or stale"
fi

# Load every shipped PHP file through the real interpreter. php -l parses;
# this actually compiles them in the environment they will run in.
# NB: php -l prints "No syntax errors detected", so counting lines containing
# "error" counts the successes. Drop those lines first.
badsyntax=$(oc sh -c "find /var/www/html/extension/$CODE -name vendor -prune -o -name '*.php' -print0 \
                      | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors detected' \
                      | grep -ciE 'parse error|fatal error' || true" | tr -d ' \r')
[ "${badsyntax:-0}" -eq 0 ] \
  && pass "all shipped PHP compiles under the runtime PHP version" \
  || fail "$badsyntax file(s) fail to compile"

# The status enum is what the callback contract hangs on; prove it loads and
# still understands the statuses the API sends.
if oc php -r "
  define('DIR_APPLICATION', '/var/www/html/catalog/');
  require '/var/www/html/extension/$CODE/system/library/spectrocoin/Enum/OrderStatus.php';
  \$e = 'Opencart\\\\Catalog\\\\Controller\\\\Extension\\\\Spectrocoin\\\\Payment\\\\Enum\\\\OrderStatus';
  foreach (['PAID','CANCELLED','TEST_PAID','LATE_CRYPTO_PAYMENT'] as \$s) {
    if (\$e::normalize(\$s)->value !== \$s) exit(1);
  }
  exit(0);" >/dev/null 2>&1; then
  pass "order-status enum loads and normalises the wire statuses"
else
  fail "order-status enum failed to load or reject a wire status"
fi

# --------------------------------------------------------------------------
# 5. Nothing may have been logged as a fatal.
# --------------------------------------------------------------------------
say "PHP error log"
log=$(oc sh -c 'cat /var/www/html/system/storage/logs/*.log /var/log/apache2/error.log 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "fatal|uncaught|parse error" | grep -iE "spectrocoin|guzzle|class .* not found" || true)
[ -z "$ours" ] && pass "no fatals attributable to the extension" \
  || { fail "fatals in the log:"; printf '%s\n' "$ours" | head -10; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: http://localhost:8082/ (admin: /admin, admin/smokesmoke1)"
else
  docker compose down -v >/dev/null 2>&1 || true
fi

echo
[ "$FAILED" -eq 0 ] && echo "smoke test PASSED" || echo "smoke test FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
