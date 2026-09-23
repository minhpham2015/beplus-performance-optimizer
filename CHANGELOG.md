# Changelog

All notable changes to this project are documented here (dev-facing —
see `readme.txt` for the user-facing WordPress.org changelog).
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.0.10] - 2026-09-23

### Fixed
- **Delay JS (Advanced mode) rewrite silently discarded when Remove Unused
  CSS was also enabled.** Both features open a nested PHP output buffer on
  `template_redirect`: `BEPLUSPB_UCSS` opens first (priority 0, outer),
  `BEPLUSPB_JS::advanced_buffer_start()` opens last (priority `PHP_INT_MAX`,
  innermost). On `shutdown`, `BEPLUSPB_UCSS::buffer_end()` used to check
  only `ob_get_level() < 1` — true whenever *any* buffer was open, not
  specifically the one it had opened — and ran before Delay JS's own buffer
  had been explicitly closed. That let it `ob_get_clean()` the wrong
  (inner, Delay JS) buffer: PHP still invoked `advanced_rewrite()` as a
  side effect of closing the buffer, but the CLEAN semantics discarded its
  rewritten `<script>` output rather than returning it, so the client
  always received the un-rewritten, un-delayed page — the setting appeared
  to do nothing on any site running both features. Reproduced live on
  `demo-wordpress.minhopsai.com`: `curl` showed 0 occurrences of
  `type="javascript/blocked"` in the response despite `js_delay=1`,
  `js_delay_mode=advanced` being active, confirmed via targeted
  `error_log()` instrumentation showing `advanced_rewrite()` *did* run and
  produce the correctly rewritten buffer, but the bytes reaching the client
  were the original, unmodified 65747-byte buffer, not the rewritten
  79413-byte one.
  **Fix:** `BEPLUSPB_JS` now records its own `ob_get_level()` before
  opening its buffer (mirroring the existing `$buffer_level` pattern
  already used by `BEPLUSPB_HTML`/`BEPLUSPB_CDN`) and closes it explicitly
  via a new `advanced_buffer_end()` hooked on `shutdown` at the lowest
  possible priority (`-PHP_INT_MAX`) using `ob_end_flush()` — guaranteeing
  it is always the first buffer closed, in correct LIFO order, and that
  the rewritten content (not the original) reaches the next buffer level.
  `BEPLUSPB_UCSS::buffer_end()` was also hardened to the same exact-level
  check already used by `BEPLUSPB_HTML`/`BEPLUSPB_CDN`
  (`ob_get_level() !== self::$buffer_level + 1`), so it can never again
  accidentally close a buffer it did not open, regardless of which other
  feature is active. Added `tests/test-buffer-nesting-regression.php`
  (RED-then-GREEN reproduction of the exact bug, now in CI) and new
  debug-log instrumentation (see below) so a similar regression is caught
  immediately instead of silently shipping.
- **Delay JS (Simple mode) dropped `type`, `integrity`, `crossorigin`,
  `nonce`, and `referrerpolicy` attributes when restoring a delayed
  script.** `BEPLUSPB_JS::delay_scripts()` stripped the original `type`
  attribute (to swap in `type="text/plain"`) but never preserved it
  anywhere, and `assets/js/delay.js` only ever copied `src` onto the
  script element it recreated after user interaction. A script originally
  marked `type="module"` came back as a plain classic script (breaking
  `import`/`export` and module execution-order guarantees), and any script
  using Subresource Integrity (`integrity`/`crossorigin`) or a
  nonce-based Content-Security-Policy (`nonce`) lost that protection/
  eligibility entirely on restore — SRI-protected scripts could silently
  stop being verified, and CSP-nonced scripts could be blocked by the
  browser after restore.
  **Fix:** `delay_scripts()` now captures the original `type` (if any)
  into a new `data-bepluspb-type` attribute before stripping it, and
  `delay.js`'s `_bepluspbLoadAll()` sets `s.type` from that attribute and
  copies `integrity`/`crossorigin`/`nonce`/`referrerpolicy` straight from
  the placeholder onto the recreated `<script>` element. Added
  `tests/test-delay-js-regression.php` (now in CI) asserting all of the
  above survive a round-trip through `delay_scripts()`.

### Added
- Debug-log instrumentation (`error_log()`, WordPress `WP_DEBUG_LOG`
  convention — silent unless debug logging is enabled) in
  `BEPLUSPB_JS::advanced_buffer_start()`/`advanced_buffer_end()` recording
  buffer open/close level mismatches, so a future conflict with another
  plugin's own output buffering shows up in `debug.log` immediately
  instead of silently discarding output like this bug did.
- `tests/test-buffer-nesting-regression.php` — standalone regression test
  reproducing the exact nested-buffer LIFO-ordering bug above (RED without
  the fix, GREEN with it); wired into CI as a dedicated
  `buffer-nesting-regression` job.
- `tests/test-delay-js-regression.php` — standalone regression test
  covering Advanced mode's buffer registration and Simple mode's
  attribute-preservation on restore; wired into CI as a dedicated
  `delay-js-regression` job.

## [1.0.9] - 2026-09-16

### Added
- **Cloudflare API integration** (new `class-bepluspb-cloudflare.php` +
  "Cloudflare" settings tab). Admin-triggered only via 2 new AJAX handlers
  (`bepluspb_cf_test_connection`, `bepluspb_cf_purge`) plus a 3rd for
  Development Mode — no outbound Cloudflare calls happen on a normal
  front-end page load, mirroring the "no outbound requests from the
  front-end path" principle already followed by `class-bepluspb-cdn.php`.
  - `BEPLUSPB_Cloudflare::test_connection_and_fetch_zone()` — validates an
    API Token and auto-detects the matching zone by this site's domain;
    zone ID/name are only ever written here, never typed in by hand.
  - `BEPLUSPB_Cloudflare::purge_all()` — wired into the existing Clear
    Cache button when `cloudflare_enabled` is on, purging Cloudflare's
    edge cache alongside the plugin's own CSS/JS cache.
  - `get_development_mode()` / `set_development_mode()` — toggle/check
    Cloudflare Development Mode (auto-expires after 3 hours on
    Cloudflare's side; "Check Status" always re-queries live, never
    trusts a locally cached value).
  - API Token stored in `wp_options` only (never a static file, never
    logged) — same sensitivity handling as the Redis AUTH password (Hard
    Rule #2).
- **Cloudflare Purge/Development Mode rate limiting.** `handle_ajax_cf_purge()`
  and the mutating branches (`on`/`off`) of `handle_ajax_cf_devmode()`
  enforce a 10-second per-user cooldown via a new
  `check_cloudflare_rate_limit()` helper (transient-backed, same pattern as
  the existing `bepluspb_cache_cleared_*` transient). Protects against an
  admin double-clicking (or a stuck tab retrying) tripping Cloudflare's own
  IP-level rate limiting. The read-only `status` dev-mode check is left
  unthrottled. Client-side, the Purge/Dev-Mode-On/Dev-Mode-Off buttons now
  disable for the cooldown window on a successful call (re-enabling
  immediately on failure) to match the server-side behavior.
- **Optional WebP/AVIF image serving** (`cdn_webp_avif` option, CDN tab).
  `BEPLUSPB_CDN::rewrite_url()` (and the two HTML-scanning rewrite paths,
  `rewrite_absolute_urls()`/`rewrite_relative_urls()`) now call new
  `maybe_swap_image_format()` for `.jpg`/`.jpeg`/`.png` paths when the option
  is on: if the visitor's browser declares AVIF/WebP support via the
  `Accept` header (new `accepted_image_formats()`, cached once per request)
  AND a same-named sibling `.avif`/`.webp` file already exists on disk
  (new `sibling_file_exists()`), the sibling path is substituted before the
  CDN domain is applied. AVIF is preferred over WebP when the browser
  accepts both and both files exist (typically 20-30% smaller at
  equivalent quality). Off by default; the plugin never generates or
  converts any image itself — this is a pure URL substitution that only
  fires when the target file is already present.
- `sibling_file_exists()` follows the same realpath()-canonicalize-then-
  prefix-check pattern as `BEPLUSPB_Minify::validate_local_path()` (Hard
  Rule #3) even though its only caller builds the candidate path itself
  (same directory as an already-CDN-eligible URL, swapped extension) —
  defence-in-depth rather than assuming that's sufficient. Verified this
  actually blocks a crafted out-of-ABSPATH path via `realpath()`
  returning a path outside the canonicalized base.
- UI note in the CDN tab's WebP/AVIF checkbox description: full-page
  caching plugins/proxies could serve a cached page's baked-in image URL
  to a visitor whose browser doesn't support that format, since the
  choice is made at render time based on that request's Accept header.

### Fixed
- **Critical: Object Cache never actually connected to Redis, despite the
  UI reporting success.** `lib/object-cache.php` read `$_bepluspb_oc_cfg`
  without an explicit `global $_bepluspb_oc_cfg;` — WordPress core
  `require_once`s this file from *inside* `wp_start_object_cache()`, so the
  top-level variable was never a real global in that scope, and
  `wp_cache_init()` always received `null` instead of the real config. A
  fail-safe catch-all swallowed the resulting error, so the settings page
  reported "connected" while Redis was never actually used. Fixed by adding
  the explicit `global` declaration. Also hardened `install_dropin()`
  (`class-bepluspb-object-cache.php`) to refuse installing the drop-in
  unless `.bepluspb_oc.json` already exists AND `enabled=true` — previously
  a blind copy on a fresh/disabled config could produce a fatal
  `Cannot redeclare function wp_cache_init()` (WSOD across the whole site,
  not recoverable from wp-admin). Verified live over real HTTP (not
  WP-CLI): `connected=true`, real Redis PING succeeded, 39 real cache keys
  observed in Redis after normal page loads; `install_dropin()` on a clean
  site with no config now returns a clear error instead of bricking it.
- **Critical: Object Cache crashed wp-admin (WSOD) whenever the `users`
  cache group held real `WP_User` objects.** WordPress core caches actual
  `WP_User` PHP objects (not scalars/arrays) in the `users` group via
  `wp_cache_add($user->ID, $user, 'users')`. With `users` in
  `global_groups` (persisted through Redis) and the Hard Rule #2
  `unserialize(..., ['allowed_classes' => false])` protection in place (a
  deliberate RCE guard, never to be removed), reading that cached value
  back produced an incomplete `stdClass` instead of a real `WP_User`,
  crashing the moment any code touched a `WP_User` property/method — a
  fatal that only surfaced once a *different* PHP-FPM worker served the
  next request (the worker that originally cached the object still had it
  live in its in-request memory, masking the bug in a single-request test).
  Fixed by moving `users` from `global_groups` to `non_persistent_groups`
  in both places it's defined (`lib/object-cache.php` and
  `beplus-performance-booster.php`); Hard Rule #2's `unserialize` guard is
  untouched. Audited the sibling `userslugs` group and confirmed it is
  safe — WP core only ever caches a plain integer ID there, never an
  object. Re-verified with real generated login cookies
  (`wp_generate_auth_cookie()`) across 5 separate requests plus
  `/wp-admin/` and `/wp-login.php` after a Redis `FLUSHALL` — clean
  `debug.log`, no more `users:*` keys in Redis post-fix.
  **Upgrade note:** sites that had Object Cache installed before this fix
  should run a Redis `FLUSHALL` once after updating, to clear any
  already-corrupted cached `WP_User` objects.
- **Critical: same crash class also affected the `site-transient` group.**
  `get_site_transient('update_core'/'update_plugins'/'update_themes')`
  also returns a real `stdClass` object, not a scalar/array — and the
  admin-bar update-count indicator calls it on *every* wp-admin page load.
  `site-transient` was also in `global_groups`, so it hit the identical
  `unserialize(allowed_classes:false)` corruption as the `users` group
  above. Fixed the same way: moved `site-transient` to
  `non_persistent_groups`. While auditing the rest of `global_groups`
  against WordPress core's actual list (`wp-includes/load.php`), also
  found and fixed a typo: the default group list had `usermeta` but core
  uses `user_meta` (underscore) — meaning that group had silently never
  actually applied since the name never matched. Re-verified with real
  login cookies across 5 separate requests to `/wp-admin/`,
  `options-general.php`, `plugins.php`, `update-core.php` — all HTTP 200,
  clean `debug.log`; confirmed no `users`/`site-transient` keys remain in
  Redis while 47 other legitimate keys continue working normally.

### Removed
- "Known future improvements" note in `CLAUDE.md` for the WebP/AVIF
  feature (was added 2026-09-06, now implemented — see above).

## [1.0.8] - 2026-09-06

### Changed
- `Requires PHP` raised 7.4 → 8.1. 7.4 reached end-of-life 2022-11-28 (no
  security patches for ~4 years); CI matrix now tests 8.1/8.2/8.3 only.
  `phpcs.xml.dist` `testVersion` updated to match. No functional/behavioral
  changes in this release.
- Fixed a CI bug (not a plugin bug), identical to the sibling
  beplus-metadata-ai-analyzer fix: the "Version Consistency Check" job's
  `grep -oP '(?<=Version:\s{0,20})\S+'` used a variable-length lookbehind,
  which GNU grep 3.11 (current Ubuntu Actions runner) rejects outright
  ("lookbehind assertion is not fixed length"), failing the job on every run
  regardless of whether versions actually matched. Replaced with the
  fixed-width `Version:\s*\K\S+`. Verified readme.txt/plugin-header versions
  were already in sync before this fix (1.0.7/1.0.7) — CI false-negative,
  not a real mismatch.

### Deferred (not in this release)
- WebP/AVIF auto-serve via CDN rewrite — flagged in the 2026-09-06
  maintenance review, deliberately deferred (scope/complexity). See
  "Known future improvements" in `CLAUDE.md`.

## [1.0.7] - 2026-09-05

### Security
- **Path traversal hardening in `BEPLUSPB_Minify::url_to_path()`** (Hard Rule
  #3, previously a known unfixed High-severity gap). The resolver used only
  `wp_normalize_path()` (slash unification) plus a `strpos()` prefix check —
  neither collapses `../`, so a crafted asset URL could resolve outside the
  intended tree. Two-part fix:
  1. **Extension allowlist:** the function now returns `false` immediately for
     any URL that isn't a `.css`/`.js` file, before touching the filesystem.
     This is the critical part — every caller (`maybe_minify_css/js`,
     `css.php` inline, `ucss.php`) only ever passes stylesheet/script URLs, so
     nothing else has a legitimate reason to be read. Without it, a URL like
     `content_url() . '/../wp-config.php'` resolved to a real path *inside*
     ABSPATH and would have survived a realpath-only check — verified live on
     a Docker WP site that pre-fix it returned `/var/www/html/wp-config.php`.
  2. **`realpath()` canonicalization:** new private `validate_local_path(
     $path, $base )` resolves `..`/symlinks and re-verifies the canonical path
     is a descendant of the (also canonicalized) allowed base
     (`WP_CONTENT_DIR` or `ABSPATH`), catching traversal that escapes the tree
     entirely (e.g. `/etc/passwd`).
  Verified: legit `.css`/`.js` still resolve correctly (minify/UCSS unaffected);
  `/etc/passwd`, `wp-config.php`, and `../`-escaping `.css` URLs all return
  `false`.

### Reliability
- `BEPLUSPB_Htaccess::add_rules()` now creates a one-time backup
  (`.htaccess.bepluspb-bak`) before the first `insert_with_markers()` write
  (Hard Rule #4 Medium gap). Guarded by the backup file's existence so
  repeated saves never overwrite the pristine original with an
  already-modified copy. Verified: first write creates the backup with the
  untouched original; second write leaves the backup unchanged.

## [1.0.6] - 2026-09-05

### Fixed
- **Remove Unused CSS: stale cache after content edits.** The used-only CSS
  cache (`BEPLUSPB_UCSS`) freshness check only compared `filemtime()` of the
  cached file against the *source stylesheet* — it had no way to detect that
  the *page content* had changed (e.g. a post edited to add a shortcode/block
  whose CSS class had previously been stripped from the cached stylesheet
  because it wasn't in use yet). Verified live: editing a post to add a new
  class kept serving the old, over-trimmed CSS (missing rules for the new
  class) until "Clear Cache" was clicked manually.
  Fixed by adding `BEPLUSPB_UCSS::register_invalidation_hooks()`, which purges
  every cached `ucss-*.css` file on `save_post` (filtered to real,
  non-autosave/non-revision saves of a public post type),
  `transition_post_status` (covers scheduled posts going live via wp-cron,
  which doesn't otherwise fire `save_post` with the final status),
  `switch_theme`, and `customize_save_after`. Registered from `plugins_loaded`
  independently of `BEPLUSPB_UCSS::init()` (which only runs on the front-end
  and would never see wp-admin's `save_post` fire). Deliberately a blanket
  purge rather than single-URL, since there's no reliable way to know which
  other pages (home, archives, widgets) render an excerpt of the changed post.
- Verified: `php -l` clean on both touched files; full activate/deactivate
  cycle on a Dockerized WP + MySQL test site with no Fatal/Warning/Notice;
  reproduced the stale-cache bug pre-fix, confirmed it's gone post-fix
  (content edit → cache purged immediately → next visit regenerates cache
  including the new class); confirmed `switch_theme` also purges correctly.

## [1.0.5] - 2026-09-04

### Security
- **Critical:** the Object Cache config file (`wp-content/.bepluspb_oc.json`),
  which can contain a plaintext Redis/Memcached AUTH password, was served
  directly over HTTP with no access restriction. Verified live: `curl` against
  the file returned `200` with the full JSON body including the password
  field. Fixed by having `write_config()` call a new `protect_config_file()`
  helper that appends a `<Files>` deny rule to `wp-content/.htaccess`
  (Apache/LiteSpeed; nginx hosts need an equivalent manual rule).
- **Critical:** the object-cache drop-in (`lib/object-cache.php`) called
  `unserialize()` on values read back from Redis/Memcached with no
  `allowed_classes` restriction. Combined with the config-leak above, an
  attacker who obtained the Redis credentials could plant a malicious
  serialized payload and trigger PHP Object Injection the moment WordPress
  bootstraps (this drop-in loads before most of WP's security layer).
  Fixed: `unserialize($raw)` → `unserialize($raw, ['allowed_classes' => false])`.

### Known gaps (not yet fixed, tracked for a future release)
- **High:** `url_to_path()` in `class-bepluspb-minify.php` (also used by
  `class-bepluspb-css.php`/`-ucss.php`) resolves enqueued asset URLs to
  filesystem paths without a `realpath()` + prefix check against
  `WP_CONTENT_DIR`. Theoretical path traversal → file disclosure via a
  compromised/misbehaving third-party script/style registration.
- **Medium:** `.htaccess` writes have no backup-before-overwrite mechanism.
- **Medium:** the "test connection" AJAX endpoint (Object Cache tab) allows
  an authenticated admin to probe arbitrary host:port combinations with no
  rate limit or logging (accepted risk — admin-only, but worth hardening).

## [1.0.4] - 2026-08-xx

### Added
- Object Cache support: persistent caching via Redis or Memcached with a WP
  drop-in (`wp-content/object-cache.php`).
- New "Object Cache" settings tab: driver selection, host/port, Redis AUTH
  password, Redis DB index, persistent connection, global/non-persistent
  groups.
- Connection test button with live AJAX result.
- Install/Remove drop-in buttons directly from the settings page.
- "⚡ Enable All Recommended" one-click button on the Dashboard tab.
- Status tab: PHP Extensions panel (Redis, Memcached, OPcache, cURL,
  GD/ImageMagick, mbstring, OpenSSL, zlib, intl availability).

## [1.0.3] - 2026-xx-xx

### Added
- QUIC.cloud (or any pull-zone) CDN support: new "CDN" settings tab
  rewrites enqueued CSS/JS, media library images (incl. srcset), and
  matching content/widget URLs to a configured CDN domain.

## [1.0.2] - 2026-xx-xx

### Added
- Remove Unused CSS: per-URL cached stripping of unused CSS rules, with
  Unused CSS Selector Safelist and Unused CSS URL Excludes options.

## [1.0.1] - 2026-xx-xx

### Added
- Delay Mode option (Simple / Advanced) for JS delay.
- JS Release Delay (ms): fallback timer releasing delayed scripts after
  above-fold images/fonts load; `0` = user interaction only.

### Changed
- JS delay now applies to all users including logged-in administrators.
- Renamed "Image-Load Wait" setting to "JS Release Delay (ms)" for accuracy.

## [1.0.0] - 2026-xx-xx

### Added
- Initial release: JavaScript delay (Simple/Advanced) and defer with
  per-script exclude list, JS Release Delay fallback timer, CSS inline
  minification and non-render-blocking preload swap, browser cache and
  gzip/brotli rules via `.htaccess`, admin bar cache panel, per-page
  cache-disable meta box, full uninstall cleanup.
