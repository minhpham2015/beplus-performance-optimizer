# Changelog

All notable changes to this project are documented here (dev-facing —
see `readme.txt` for the user-facing WordPress.org changelog).
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
