#!/usr/bin/env bash
set -euo pipefail
export WP_CLI_ALLOW_ROOT=1

# Real WordPress + MySQL + Redis behavior coverage, over real HTTP (php -S):
#  * Minify: enqueued CSS/JS are rewritten to cache URLs that actually serve minified bytes;
#    .min.* skipped; a .css symlink to wp-config.php never leaks into the cache.
#  * CDN: local asset URLs in the rendered page move to the CDN host, links stay on origin.
#  * Object Cache: after real install, persistent keys land in Redis and wp_cache_flush()
#    removes only this site's namespace (foreign keys in the same Redis DB survive).
ROOT="$(mktemp -d /tmp/bepluspb-fe-integration-XXXXXX)"
DB="bepluspb_fe_integration_${RANDOM}_$$"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${BEPLUSPB_TEST_DB_HOST:-localhost}"
DB_USER="${BEPLUSPB_TEST_DB_USER:-root}"
DB_PASSWORD="${BEPLUSPB_TEST_DB_PASSWORD:-}"
MYSQL=(mysql -h "$DB_HOST" -u "$DB_USER")
if [[ -n "$DB_PASSWORD" ]]; then MYSQL+=("-p$DB_PASSWORD"); fi
REDIS_HOST="${BEPLUSPB_TEST_REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${BEPLUSPB_TEST_REDIS_PORT:-6379}"
REDIS_CLI=(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -n 15)
PORT="${BEPLUSPB_TEST_HTTP_PORT:-18089}"
BASE="http://127.0.0.1:${PORT}"
SERVER_PID=""
cleanup() {
  [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" >/dev/null 2>&1 || true
  "${REDIS_CLI[@]}" --scan --pattern 'fxsite:*' 2>/dev/null | xargs -r "${REDIS_CLI[@]}" DEL >/dev/null 2>&1 || true
  "${REDIS_CLI[@]}" DEL foreign:key >/dev/null 2>&1 || true
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`" >/dev/null 2>&1 || true
  rm -rf "$ROOT"
}
trap cleanup EXIT INT TERM
fail() { echo "FAIL: $*" >&2; exit 1; }
assert_contains() { grep -qF -- "$2" <<<"$1" || fail "$3 (missing: $2)"; }
assert_not_contains() { ! grep -qF -- "$2" <<<"$1" || fail "$3 (unexpected: $2)"; }

if ! "${REDIS_CLI[@]}" ping >/dev/null 2>&1; then
  [[ "${BEPLUSPB_REQUIRE_REDIS:-0}" == "1" ]] && fail "Redis unreachable at ${REDIS_HOST}:${REDIS_PORT}"
  echo "SKIP: Redis unreachable"; exit 0
fi

"${MYSQL[@]}" -e "CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
wp core download --path="$ROOT" --quiet
wp config create --path="$ROOT" --dbname="$DB" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check --quiet
wp config set WP_CACHE_KEY_SALT fxsite --type=constant --path="$ROOT" --quiet
wp core install --path="$ROOT" --url="$BASE" --title=FE --admin_user=admin --admin_password='integration-only' --admin_email=fe@example.invalid --skip-email --quiet
cp -a "$PLUGIN_DIR" "$ROOT/wp-content/plugins/beplus-performance-optimizer"
wp plugin activate beplus-performance-optimizer --path="$ROOT" --quiet
CANARY="WPCONFIG-CANARY-$RANDOM$RANDOM"
printf '\n// %s\n' "$CANARY" >> "$ROOT/wp-config.php"

# --- Fixtures ----------------------------------------------------------------
FX="$ROOT/wp-content/bepluspb-fixtures"; mkdir -p "$FX"
printf '/*! lic-keep */\n/* drop-css */\nbody {\n  color : red ;\n}\n.a   >   .b { margin: 0 ; }\n' > "$FX/fx.css"
printf '// drop-js\nvar fx = "http://x.test/a"; /* drop-block */\n' > "$FX/fx.js"
printf 'a{b:c}' > "$FX/pre.min.css"
ln -s "$ROOT/wp-config.php" "$FX/evil.css"
mkdir -p "$ROOT/wp-content/mu-plugins"
cat > "$ROOT/wp-content/mu-plugins/fx-enqueue.php" <<'PHP'
<?php
add_action( 'wp_enqueue_scripts', function () {
	$b = content_url( '/bepluspb-fixtures/' );
	wp_enqueue_style( 'fx-css', $b . 'fx.css', array(), null );
	wp_enqueue_style( 'fx-pre', $b . 'pre.min.css', array(), null );
	wp_enqueue_style( 'fx-evil', $b . 'evil.css', array(), null );
	wp_enqueue_script( 'fx-js', $b . 'fx.js', array(), null, true );
} );
PHP

OPTS='{"cache_enabled":1,"minify_css_files":1,"minify_js_files":1,"cache_for_logged_in":1,"cdn_enabled":0}'
wp option update bepluspb_settings "$OPTS" --format=json --path="$ROOT" >/dev/null
( cd "$ROOT" && php -S "127.0.0.1:${PORT}" -t "$ROOT" >/dev/null 2>&1 & echo $! > "$ROOT/.pid" )
SERVER_PID="$(cat "$ROOT/.pid")"
for _ in $(seq 1 20); do curl -fs "$BASE/" >/dev/null 2>&1 && break; sleep 0.5; done

# --- Minify ------------------------------------------------------------------
HTML="$(curl -fs "$BASE/")"
assert_contains "$HTML" "/bepluspb-cache/fx-css-" "CSS rewritten to cache URL"
assert_contains "$HTML" "/bepluspb-cache/fx-js-" "JS rewritten to cache URL"
assert_contains "$HTML" "bepluspb-fixtures/pre.min.css" ".min.css left alone"
assert_contains "$HTML" "bepluspb-fixtures/evil.css" "evil symlink URL left unchanged"
assert_not_contains "$HTML" "bepluspb-cache/fx-evil" "evil symlink never cached"
CSS_URL="$(grep -o "http[^'\"]*/bepluspb-cache/fx-css-[^'\"?]*" <<<"$HTML" | head -1)"
JS_URL="$(grep -o "http[^'\"]*/bepluspb-cache/fx-js-[^'\"?]*" <<<"$HTML" | head -1)"
CSS_BODY="$(curl -fs "$CSS_URL")"; JS_BODY="$(curl -fs "$JS_URL")"
assert_contains "$CSS_BODY" "lic-keep" "license comment kept over HTTP"
assert_not_contains "$CSS_BODY" "drop-css" "CSS comment stripped over HTTP"
assert_contains "$CSS_BODY" "body{color:red}" "CSS compacted over HTTP"
assert_not_contains "$JS_BODY" "drop-js" "JS line comment stripped over HTTP"
assert_contains "$JS_BODY" '"http://x.test/a"' "JS string with // preserved"
if grep -rqF -- "$CANARY" "$ROOT/wp-content/uploads/bepluspb-cache" 2>/dev/null; then fail "wp-config contents leaked into cache dir"; fi

# --- CDN ---------------------------------------------------------------------
wp option update bepluspb_settings '{"cache_enabled":1,"minify_css_files":1,"minify_js_files":1,"cache_for_logged_in":1,"cdn_enabled":1,"cdn_url":"https://cdn.example.net"}' --format=json --path="$ROOT" >/dev/null
HTML="$(curl -fs "$BASE/")"
assert_contains "$HTML" "https://cdn.example.net/wp-content/uploads/bepluspb-cache/fx-css-" "minified CSS served from CDN host"
assert_contains "$HTML" "https://cdn.example.net/wp-content/uploads/bepluspb-cache/fx-js-" "minified JS served from CDN host"
assert_contains "$HTML" "href=\"$BASE/" "navigation links stay on origin"
wp option update bepluspb_settings "$OPTS" --format=json --path="$ROOT" >/dev/null

# --- Object Cache against real Redis -----------------------------------------
R_HOST="$REDIS_HOST" R_PORT="$REDIS_PORT" wp eval '
$o = wp_parse_args( array( "object_cache_enabled" => 1, "object_cache_driver" => "redis", "object_cache_host" => getenv( "R_HOST" ), "object_cache_port" => (int) getenv( "R_PORT" ), "object_cache_db" => 15, "object_cache_persistent" => 0 ), bepluspb_get_options() );
update_option( BEPLUSPB_OPTIONS_KEY, array_merge( get_option( BEPLUSPB_OPTIONS_KEY, array() ), $o ) );
bepluspb_flush_options_cache();
$r = BEPLUSPB_Object_Cache::install_with_config( $o );
if ( empty( $r["success"] ) ) { fwrite( STDERR, "install failed: " . ( $r["message"] ?? "?" ) . "\n" ); exit( 1 ); }
' --path="$ROOT"
[[ -f "$ROOT/wp-content/object-cache.php" ]] || fail "drop-in installed"
"${REDIS_CLI[@]}" FLUSHDB >/dev/null
curl -fs "$BASE/" >/dev/null; curl -fs "$BASE/?s=a" >/dev/null
OWN_BEFORE="$("${REDIS_CLI[@]}" --scan --pattern 'fxsite:*' | wc -l)"
[[ "$OWN_BEFORE" -gt 0 ]] || fail "real page loads populated Redis (found $OWN_BEFORE keys)"
"${REDIS_CLI[@]}" SET foreign:key keep >/dev/null
wp eval 'if ( ! wp_cache_flush() ) { exit( 1 ); }' --path="$ROOT" || fail "wp_cache_flush() reported failure"
OWN_AFTER="$("${REDIS_CLI[@]}" --scan --pattern 'fxsite:*' | wc -l)"
[[ "$OWN_AFTER" -eq 0 ]] || fail "flush removed this site's keys (left: $OWN_AFTER)"
[[ "$("${REDIS_CLI[@]}" GET foreign:key)" == "keep" ]] || fail "flush must not delete foreign keys sharing the Redis DB"
HTTP_AFTER="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")"
[[ "$HTTP_AFTER" == "200" ]] || fail "front page still 200 after flush (got $HTTP_AFTER)"
echo "PASS: real WordPress + Redis front-end behavior integration"
