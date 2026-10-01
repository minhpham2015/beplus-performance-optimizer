#!/usr/bin/env bash
set -euo pipefail
export WP_CLI_ALLOW_ROOT=1

# Real WordPress integration coverage for the foreign drop-in transaction.
# This always creates and destroys its own WordPress tree and database.
ROOT="$(mktemp -d /tmp/bepluspb-wp-integration-XXXXXX)"
DB="bepluspb_oc_integration_${RANDOM}_$$"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${BEPLUSPB_TEST_DB_HOST:-localhost}"
DB_USER="${BEPLUSPB_TEST_DB_USER:-root}"
DB_PASSWORD="${BEPLUSPB_TEST_DB_PASSWORD:-}"
MYSQL=(mysql -h "$DB_HOST" -u "$DB_USER")
if [[ -n "$DB_PASSWORD" ]]; then MYSQL+=("-p$DB_PASSWORD"); fi

# Redis sentinel setup: proves the drop-in backup/replace/restore transaction
# never touches Redis namespaces outside the plugin's own configured
# database (db 15 below). Uses db 14 (an adjacent-but-different db on the
# same instance) and db 0 (the default db, standing in for another
# site/app sharing this Redis instance) as sentinels.
REDIS_HOST="${BEPLUSPB_TEST_REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${BEPLUSPB_TEST_REDIS_PORT:-6379}"
REDIS_PASSWORD="${BEPLUSPB_TEST_REDIS_PASSWORD:-}"
REDIS_CLI=(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT")
if [[ -n "$REDIS_PASSWORD" ]]; then REDIS_CLI+=(-a "$REDIS_PASSWORD" --no-auth-warning); fi
REDIS_AVAILABLE=0
SENTINEL_KEY="bepluspb-sentinel-$(basename "$ROOT")"
SENTINEL_VALUE="untouched-$(date +%s)-$$"

# Tracks any fixture created OUTSIDE $ROOT so it is removed even if the
# script dies (failed assertion, signal) between creating it and its own
# normal cleanup lines below. A plain file path list, one per line.
OUTSIDE_FIXTURES="$(mktemp /tmp/bepluspb-outside-fixtures-XXXXXX)"
track_outside() { printf '%s\n' "$1" >> "$OUTSIDE_FIXTURES"; }
cleanup() {
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`" >/dev/null 2>&1 || true
  rm -rf "$ROOT"
  if [[ -f "$OUTSIDE_FIXTURES" ]]; then
    while IFS= read -r path; do
      [[ -n "$path" ]] && rm -rf "$path"
    done < "$OUTSIDE_FIXTURES"
    rm -f "$OUTSIDE_FIXTURES"
  fi
  if [[ "$REDIS_AVAILABLE" -eq 1 ]]; then
    "${REDIS_CLI[@]}" -n 0 DEL "$SENTINEL_KEY" >/dev/null 2>&1 || true
    "${REDIS_CLI[@]}" -n 14 DEL "$SENTINEL_KEY" >/dev/null 2>&1 || true
    "${REDIS_CLI[@]}" -n 15 DEL "$SENTINEL_KEY" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT INT TERM

# Redis reachability is checked AFTER the EXIT trap above is armed, so the
# hard failure below (when BEPLUSPB_REQUIRE_REDIS=1) still cleans up $ROOT
# and OUTSIDE_FIXTURES instead of leaking them. Retries ride out a container
# service that is still starting up (avoids CI flakiness from a single
# immediate ping against a not-yet-ready redis: service container).
redis_ping_ok=0
for _attempt in 1 2 3 4 5; do
  if command -v redis-cli >/dev/null 2>&1 && "${REDIS_CLI[@]}" ping >/dev/null 2>&1; then
    redis_ping_ok=1
    break
  fi
  sleep 1
done
if [[ "$redis_ping_ok" -eq 1 ]]; then
  REDIS_AVAILABLE=1
elif [[ "${BEPLUSPB_REQUIRE_REDIS:-0}" == "1" ]]; then
  # In CI (and anywhere else that opts in) the purge-boundary sentinel
  # assertion is mandatory, not advisory — a missing redis-cli or an
  # unreachable Redis must fail the job loudly instead of silently
  # skipping real coverage while the job still reports green.
  echo "FAIL: BEPLUSPB_REQUIRE_REDIS=1 but redis-cli is missing or Redis at ${REDIS_HOST}:${REDIS_PORT} is unreachable after 5 attempts; purge-boundary coverage cannot run." >&2
  exit 1
fi

# The symlink-rejection fixture below must live outside $ROOT (it is a
# symlink target that must NOT be inside the WordPress tree we delete), but
# its path is deterministic (derived from $ROOT, not random) so the EXIT
# trap can remove it unconditionally even if the PHP integration script
# dies between creating it and its own normal cleanup lines.
export BEPLUSPB_TEST_OUTSIDE_FIXTURE="/tmp/bepluspb-outside-$(basename "$ROOT")"
track_outside "$BEPLUSPB_TEST_OUTSIDE_FIXTURE"

# Seed sentinel keys in Redis namespaces the plugin must never touch:
# db 0 (default db, standing in for another site/app sharing this Redis
# instance) and db 14 (an adjacent-but-different db from the plugin's own
# configured db 15). If these survive byte-identical after the full
# backup/replace/restore transaction below, the transaction proved it
# never issued a cross-namespace FLUSHALL/FLUSHDB or deleted foreign keys.
if [[ "$REDIS_AVAILABLE" -eq 1 ]]; then
  "${REDIS_CLI[@]}" -n 0 SET "$SENTINEL_KEY" "$SENTINEL_VALUE" >/dev/null
  "${REDIS_CLI[@]}" -n 14 SET "$SENTINEL_KEY" "$SENTINEL_VALUE" >/dev/null
  "${REDIS_CLI[@]}" -n 15 SET "$SENTINEL_KEY" "$SENTINEL_VALUE" >/dev/null
fi

"${MYSQL[@]}" -e "CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
wp core download --path="$ROOT" --skip-content --quiet
wp config create --path="$ROOT" --dbname="$DB" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check --quiet
wp core install --path="$ROOT" --url=http://bepluspb-integration.invalid --title=Integration --admin_user=admin --admin_password='integration-only' --admin_email=integration@example.invalid --skip-email --quiet
mkdir -p "$ROOT/wp-content/plugins"
cp -a "$PLUGIN_DIR" "$ROOT/wp-content/plugins/beplus-performance-optimizer"
wp plugin activate beplus-performance-optimizer --path="$ROOT" --quiet
cat > "$ROOT/wp-content/.bepluspb_oc.json" <<'JSON'
{"enabled":true,"driver":"redis","host":"127.0.0.1","port":6379,"password":"","db":15,"persistent":false,"global_groups":[],"non_persistent_groups":[]}
JSON

# Core's cache.php is a complete, executable foreign drop-in. The extra marker
# makes provider detection deterministic without changing its behavior.
{ printf '%s\n' '<?php // Redis Object Cache by Integration Fixture'; sed '1d' "$ROOT/wp-includes/cache.php"; } > "$ROOT/wp-content/object-cache.php"
chmod 0640 "$ROOT/wp-content/object-cache.php"
touch -t 202001020304.05 "$ROOT/wp-content/object-cache.php"
FOREIGN_SHA="$(sha256sum "$ROOT/wp-content/object-cache.php" | cut -d' ' -f1)"
FOREIGN_MODE="$(stat -c '%a' "$ROOT/wp-content/object-cache.php")"
FOREIGN_MTIME="$(stat -c '%Y' "$ROOT/wp-content/object-cache.php")"

cat > "$ROOT/integration.php" <<'PHP'
<?php
$plugin = WP_PLUGIN_DIR . '/beplus-performance-optimizer';
require_once $plugin . '/includes/class-bepluspb-dropin-workflow.php';
function must( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}
$content = WP_CONTENT_DIR;
$source  = realpath( $plugin . '/lib/object-cache.php' );
$foreign = file_get_contents( $content . '/object-cache.php' );
$sha     = hash( 'sha256', $foreign );
$mode    = fileperms( $content . '/object-cache.php' ) & 0777;
$mtime   = filemtime( $content . '/object-cache.php' );
$hooks   = array(
	'backend_test' => static fn() => array( 'success' => true ),
	'manifest_key' => static fn() => str_repeat( 'integration-key-', 3 ),
);
$workflow = new BEPLUSPB_Dropin_Workflow( $content, $source, $hooks );
exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $source ) . ' 2>&1', $lint_output, $lint_rc );
must( is_file( $source ) && is_readable( $source ) && 0 === $lint_rc, 'bundled source usable: ' . PHP_BINARY . ' / ' . implode( ' ', $lint_output ) );

// Real filesystem path/symlink checks in the actual throwaway wp-content.
// Path is supplied by the parent shell script (deterministic, derived from
// $ROOT) so its EXIT trap can remove it even if this script dies before
// reaching the unlink()/rmdir() cleanup below.
$outside = getenv( 'BEPLUSPB_TEST_OUTSIDE_FIXTURE' );
must( is_string( $outside ) && '' !== $outside, 'outside fixture path provided by harness' );
must( ! file_exists( $outside ) && mkdir( $outside, 0700 ), 'outside fixture directory created fresh (not pre-existing/attacker-planted)' );
symlink( $outside, $content . '/bepluspb-backups' );
must( empty( $workflow->replace()['success'] ), 'symlinked backup directory fails closed' );
must( array( '.', '..' ) === scandir( $outside ), 'symlink target was not mutated' );
unlink( $content . '/bepluspb-backups' );
rmdir( $outside );

$result = $workflow->replace();
must( ! empty( $result['success'] ), 'foreign drop-in backup and replacement succeeds: ' . ( $result['message'] ?? 'no message' ) );
must( defined( 'BEPLUSPB_DROPIN_BUILD_ID' ) || false !== strpos( file_get_contents( $content . '/object-cache.php' ), 'BEPLUSPB_DROPIN_BUILD_ID' ), 'installed identity is present' );
must( hash_equals( hash_file( 'sha256', $source ), hash_file( 'sha256', $content . '/object-cache.php' ) ), 'installed bytes equal bundled drop-in' );
must( is_file( $result['backup'] ) && 0 !== strpos( ltrim( file_get_contents( $result['backup'] ) ), '<?php' ), 'backup is non-executable' );
must( hash_equals( $sha, hash( 'sha256', base64_decode( file_get_contents( $result['backup'] ), true ) ) ), 'backup decodes byte-perfectly' );
$manifest = json_decode( file_get_contents( $content . '/bepluspb-backups/restore-manifest.json' ), true );
must( is_array( $manifest ) && ! empty( $manifest['mac'] ) && basename( $manifest['backup'] ) === $manifest['backup'], 'authenticated path-safe manifest exists' );

// The selected restore backup survives retention churn.
for ( $i = 0; $i < 5; ++$i ) {
	$workflow->backup_file( $content . '/object-cache.php', 'retention-' . $i, $result['backup'] );
}
must( is_file( $result['backup'] ), 'manifest-selected backup survives retention' );

// A real flock holder causes a concurrent transaction to fail without mutation.
$before = hash_file( 'sha256', $content . '/object-cache.php' );
$lock = fopen( $content . '/.bepluspb-dropin.lock', 'c+' );
must( $lock && flock( $lock, LOCK_EX | LOCK_NB ), 'test acquires transaction lock' );
must( empty( $workflow->restore()['success'] ), 'lock contention fails closed' );
must( hash_equals( $before, hash_file( 'sha256', $content . '/object-cache.php' ) ), 'contended transaction leaves target unchanged' );
flock( $lock, LOCK_UN ); fclose( $lock );

// Forced post-activation health failure automatically rolls back exact bytes.
$failed = new BEPLUSPB_Dropin_Workflow( $content, $source, $hooks + array( 'health_probe' => static fn() => false ) );
$rollback = $failed->restore();
must( empty( $rollback['success'] ) && ! empty( $rollback['rolled_back'] ), 'failed restore health probe reports rollback' );
must( hash_equals( $before, hash_file( 'sha256', $content . '/object-cache.php' ) ), 'automatic rollback restores active Beplus bytes' );

$restored = $workflow->restore();
must( ! empty( $restored['success'] ), 'foreign drop-in restore succeeds through real WordPress bootstrap health probe' );
must( hash_equals( $sha, hash_file( 'sha256', $content . '/object-cache.php' ) ), 'foreign bytes restore exactly' );
must( $mode === ( fileperms( $content . '/object-cache.php' ) & 0777 ), 'portable mode metadata restores' );
must( $mtime === filemtime( $content . '/object-cache.php' ), 'mtime metadata restores' );

// Tampered traversal is rejected and cannot alter the restored foreign file.
$manifest_path = $content . '/bepluspb-backups/restore-manifest.json';
$manifest['backup'] = '../../wp-config.php';
file_put_contents( $manifest_path, wp_json_encode( $manifest ) );
must( empty( $workflow->restore()['success'] ), 'manifest traversal fails closed' );
must( hash_equals( $sha, hash_file( 'sha256', $content . '/object-cache.php' ) ), 'traversal attempt leaves target unchanged' );
echo "PASS: real WordPress foreign drop-in transaction integration\n";
PHP

wp eval-file "$ROOT/integration.php" --path="$ROOT"
[[ "$(sha256sum "$ROOT/wp-content/object-cache.php" | cut -d' ' -f1)" == "$FOREIGN_SHA" ]]
[[ "$(stat -c '%a' "$ROOT/wp-content/object-cache.php")" == "$FOREIGN_MODE" ]]
[[ "$(stat -c '%Y' "$ROOT/wp-content/object-cache.php")" == "$FOREIGN_MTIME" ]]

# Purge-boundary proof: the drop-in backup/replace/restore transaction above
# touched only its own configured Redis db (15). Sentinels in db 0 (stand-in
# for another site/app sharing the instance) and db 14 (an adjacent, unrelated
# db) must be byte-identical and still present — a FLUSHALL/FLUSHDB on the
# whole instance or a global-transient-style wipe would have erased them.
if [[ "$REDIS_AVAILABLE" -eq 1 ]]; then
  for db in 0 14 15; do
    got="$("${REDIS_CLI[@]}" -n "$db" GET "$SENTINEL_KEY")"
    if [[ "$got" != "$SENTINEL_VALUE" ]]; then
      echo "FAIL: Redis db $db sentinel was mutated by the drop-in transaction (expected '$SENTINEL_VALUE', got '$got')" >&2
      exit 1
    fi
  done
  echo "PASS: object-cache transaction left foreign Redis namespaces (db 0, db 14) and its own db 15 sentinel intact"
else
  echo "SKIP: redis-cli not reachable at ${REDIS_HOST}:${REDIS_PORT}; purge-boundary sentinel check not run" >&2
fi
