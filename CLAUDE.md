# CLAUDE.md — Beplus Performance Booster

Guidance for Claude Code (or any AI agent) working in this repository.

## What this is

A WordPress front-end performance plugin: JS/CSS minification, lazy load,
asset cleanup (emoji/embed/block-CSS/WooCommerce-on-non-shop-pages), CDN
rewrite, Redis/Memcached object cache (drop-in), `.htaccess` browser-cache
and compression rules. Published on WordPress.org as
`beplus-performance-optimizer` (note: internal slug/class prefix is
`beplus-performance-booster`/`BEPLUSPB` — the plugin was renamed after the
WordPress.org listing was already live; don't "fix" this mismatch without
checking whether it would break the SVN slug binding).

## Architecture

- `beplus-performance-booster.php` — bootstrap, defines `BEPLUSPB_VERSION`,
  loads `includes/class-bepluspb-*.php`.
- `includes/class-bepluspb-admin.php` — settings page (8 tabs), AJAX
  handlers (`toggle_cache`, `test_oc`, `install_oc`, `remove_oc`,
  `clear_cache`, `quick_enable`, `enable_all_recommended`).
- `includes/class-bepluspb-htaccess.php` — writes browser-cache/gzip/brotli
  rules into the root `.htaccess` via WordPress core's
  `insert_with_markers()`. Rules are hardcoded/static — never make them
  accept unsanitized user input.
- `includes/class-bepluspb-object-cache.php` — manages the object-cache
  drop-in lifecycle (install/remove `wp-content/object-cache.php`, write/
  read `wp-content/.bepluspb_oc.json` config, connection test). **This is
  the highest-risk file in the plugin — see Hard Rules below.**
- `lib/object-cache.php` — the actual drop-in WordPress core loads directly
  into `wp-content/object-cache.php`. Runs extremely early in bootstrap,
  before most WordPress security machinery. Any bug here has an outsized
  blast radius compared to a normal plugin file.
- `includes/class-bepluspb-minify.php`, `-css.php`, `-js.php`, `-html.php`,
  `-ucss.php` — asset processing pipeline; `url_to_path()` in minify.php
  resolves enqueued URLs to filesystem paths and is shared by css.php/ucss.php.
- `includes/class-bepluspb-cdn.php` — rewrites enqueued/media/content URLs
  to a configured CDN domain. Does NOT make outbound HTTP requests itself
  (string rewrite only) — keep it that way, don't add a "verify CDN URL is
  reachable" feature that turns this into an SSRF vector.
- `includes/class-bepluspb-cleanup.php` — despite the name, this only
  dequeues default WP scripts/styles (emoji, embed, block CSS, WooCommerce
  on non-shop pages). It does NOT touch the database. If you add real DB
  cleanup (revisions/transients/spam comments), it needs its own security
  review — see Hard Rules #4.

## Hard rules — do not violate

1. **`wp-content/.bepluspb_oc.json` must always be `.htaccess`-protected.**
   It can contain a plaintext Redis/Memcached AUTH password.
   `BEPLUSPB_Object_Cache::write_config()` must always call
   `protect_config_file()` before writing the file. This was a live,
   verified vulnerability (fixed v1.0.5) — `curl` against
   `wp-content/.bepluspb_oc.json` returned the full config including the
   password field before the fix. CI enforces this
   (`security-regression-guard` job) but never remove the call manually.

2. **`lib/object-cache.php`'s `unserialize($raw, ...)` must always pass
   `['allowed_classes' => false]`.** The drop-in runs before most of
   WordPress's security layer; combined with rule #1 leaking Redis
   credentials, unrestricted `unserialize()` on cache-backend data is a
   PHP Object Injection → potential RCE chain (fixed v1.0.5). CI enforces
   this too. If a future feature genuinely needs to cache a PHP object,
   solve it with `wp_json_encode()`/`json_decode()` instead of loosening
   this restriction.

3. **Any path built from a URL (enqueued script/style src, uploaded file,
   etc.) that gets turned into a filesystem path MUST be validated with
   `realpath()` + a prefix check against `WP_CONTENT_DIR`/`ABSPATH` before
   `file_exists()`/`file_get_contents()`.** `url_to_path()` in
   `class-bepluspb-minify.php` does not currently do this (known High-
   severity gap from the 2026-09 security review, not yet fixed) — don't
   copy this pattern into new code, and fix it here if you're touching this
   function for any other reason.

4. **`.htaccess` writes go through `insert_with_markers()` only** — never
   hand-roll regex-based file editing for `.htaccess`. Always check
   `wp_is_writable()` first. Consider backing up the file before the first
   write in a session (not yet implemented — Medium-severity gap from the
   security review).

5. **Every AJAX handler needs nonce check BEFORE capability check**, and
   `current_user_can('manage_options')` (this plugin is admin-only, no
   lower-privilege AJAX surface exists — keep it that way). This has been
   consistent across every handler reviewed; don't be the exception.

6. **No raw `$wpdb->query()`/`get_results()` without `$wpdb->prepare()`.**
   Currently there is exactly one `$wpdb->delete()` call in the entire
   codebase (`uninstall.php`), using the safe array-condition form. Keep
   database access surface minimal — this plugin's job is front-end asset
   optimization, not data management.

## Testing before every PR

No PHPUnit suite yet. Minimum bar:

```bash
# 1. Syntax check every touched file
php -l includes/class-bepluspb-whatever.php

# 2. Docker WordPress + MySQL, mount the plugin, activate, click through
#    all 8 settings tabs checking for Fatal error / Warning / Notice.
# 3. If you touched Object Cache: after saving settings, curl the config
#    file directly — it MUST return 403, not 200:
curl -s -o /dev/null -w "%{http_code}\n" http://your-test-site/wp-content/.bepluspb_oc.json
# 4. If you touched Minify/CDN: load a real front-end page, confirm no
#    Fatal error, confirm assets still render (view-source, check enqueued
#    script/style URLs actually resolve).
# 5. If you touched .htaccess writing: check the file after save/remove —
#    confirm the marker block appears/disappears cleanly and doesn't
#    corrupt other directives already in the file.
```

## Release procedure

See `docs/RELEASE.md`. Note this repo's default branch is `master`, not
`main` (differs from the sibling `beplus-metadata-ai-analyzer` repo — check
before pushing).

**Dev-only files never ship to WordPress.org:** `.github/`, `CLAUDE.md`,
`CHANGELOG.md`, `docs/`, `composer.json`/`composer.lock`, `phpcs.xml.dist`,
`.gitignore` are repo infrastructure only. The `rsync` command in
`docs/RELEASE.md` explicitly excludes all of them — if you add a new
dev-only file/folder at the repo root, add it to that exclude list too, or
it will accidentally get published to every WordPress site running this
plugin.

## Things NOT to do

- Don't let the "Cleanup" module grow into real database cleanup (revision/
  transient/comment deletion) without a dedicated security review — that's
  the highest-risk category of change for this plugin (SQL surface,
  irreversible data loss) and the current 6.5/10 security posture assumes
  zero DB write access outside `uninstall.php`.
- Don't add a "test connection" style AJAX endpoint that lets an admin
  target arbitrary hosts/ports without at least logging what was tested —
  `test_oc` already does this for Redis/Memcached testing (accepted risk,
  admin-only) but don't add more of these casually.
- Don't remove the `.min.js`/`.min.css` skip check in the minifier — it
  exists to avoid double-processing already-minified vendor files.
