=== Beplus Performance Booster ===
Contributors: bearsthemes, minhphamit
Tags: performance, lazy load, cache, minify, optimization
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.0.8
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WordPress optimizer with JS/CSS minification, lazy loading, asset cleanup, and browser cache headers. Zero dependencies.

== Description ==

Beplus Performance Booster is a no-bloat performance plugin that gives you fine-grained
control over your site's front-end assets. All features can be toggled independently
from a single settings page (Settings > Beplus Performance Booster).

All front-end optimisations are bypassed for logged-in administrators so you never
accidentally break the admin panel or your own editing experience.

**Feature summary:**

* JavaScript delay (Simple / Advanced mode) and defer with per-script exclude list
* JS Release Delay: image-load fallback timer for releasing delayed scripts
* CSS file and inline minification with cache
* Remove Unused CSS: per-URL cached stripping of unused CSS rules
* Non-render-blocking stylesheet loading
* Lazy load images with IntersectionObserver fallback
* Remove emoji, wp-embed, Gutenberg CSS, WooCommerce assets on non-shop pages
* HTML minification and comment stripping
* Browser cache and gzip/brotli rules via .htaccess
* Per-page option to disable the cache for specific posts/pages
* Custom CDN (pull-zone) URL rewriting for static assets

== Settings Reference ==

Navigate to **Settings > Beplus Performance Booster** to configure the plugin.

---

= ⚡ JavaScript Optimization =

**Delay JS Execution**
Default: off

Delays all non-excluded front-end scripts until the first user interaction (click,
scroll, keydown, or touchstart), or until the JS Release Delay timer fires —
whichever comes first.

*Delay Mode* — choose how scripts are intercepted:

* **Simple** — converts WordPress-enqueued external scripts to `text/plain`
  placeholders and replays them on first interaction. Low risk, no event-queue replay.
* **Advanced** — output-buffer approach that intercepts every `<script>` tag on the
  page (including hardcoded theme scripts and dynamically injected ones) via
  MutationObserver. Replays `DOMContentLoaded` and `window.load` event queues in
  correct order. Test thoroughly before enabling on production.

*JS Release Delay (ms)* — fallback timer (ms) that releases delayed scripts after
above-fold images and fonts finish loading, without requiring user interaction.
`0` = disabled — only user interaction releases delayed scripts.

**Defer Non-Critical JS**
Default: off

Adds the `defer` attribute to every non-excluded `<script>` tag so scripts are
fetched in parallel and executed after the HTML is parsed. jQuery and
jquery-migrate are always protected from deferral.

**Exclude JS Files**
Default: empty

One URL keyword per line. Any script whose `src` attribute contains a listed
keyword is excluded from both Delay and Defer. Use this to protect scripts that
must run immediately (e.g. analytics that need to capture the first page view).

Examples: `jquery`, `woocommerce`, `my-critical-script`

---

= 🎨 CSS Optimization =

**Minify Inline CSS**
Default: off

Strips block comments (`/* … */`), collapses redundant whitespace, and removes
trailing semicolons from every inline `<style>` block in `<head>`. License
comments (`/*! … */`) are preserved. Spaces around `+` are intentionally kept
so `calc(100% + 20px)` expressions are never broken.

**Minify CSS Files**
Default: off
Requires: wp-content/uploads/bepluspb-cache/ directory to be writable

Minifies every enqueued external CSS file and serves the cached version from
`wp-content/uploads/bepluspb-cache/`. Already-minified `*.min.css` files and
external CDN stylesheets are skipped automatically. Cache files are named using a
12-character content MD5 hash so they self-invalidate whenever the source changes.

**Non-Render-Blocking CSS**
Default: off

Converts `<link rel="stylesheet">` tags to the preload + onload swap pattern:

    <link rel="preload" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" ...></noscript>

This prevents stylesheets from blocking the initial render. A `<noscript>` fallback
ensures CSS loads correctly even when JavaScript is disabled.

Tip: if you see a flash of unstyled content (FOUC), add your theme's main stylesheet
URL keyword to the Exclude CSS Files list.

**Exclude CSS Files**
Default: empty

One URL keyword per line. Stylesheets whose `href` contains a listed keyword are
left completely untouched by the Non-Blocking, Inline All CSS, Minify CSS Files,
and Remove Unused CSS features.

**Remove Unused CSS**
Default: off
Requires: wp-content/uploads/bepluspb-cache/ directory to be writable

Scans the fully rendered page and strips CSS rules whose selectors never appear
in that page's HTML, caching the trimmed stylesheet per URL. This is a static
text match (not a real browser render): the first visit to a URL generates the
trimmed file in the background while still serving the original CSS; later
visits to the same URL load the trimmed version. Classes added dynamically by
JavaScript after page load will not be detected as "used" — add them to the
Unused CSS Selector Safelist below.

**Unused CSS Selector Safelist**
Default: empty

One selector or keyword per line (trailing `*` wildcard supported). Rules
matching these are always kept regardless of whether they were detected as
used. Use this for JS-toggled classes (menus, modals, tabs, accordions) and
plugin/page-builder dynamic states.

Example: `.active`, `.is-open*`, `.woocommerce-*`

**Unused CSS URL Excludes**
Default: empty

One URL keyword per line. Matching pages never have unused CSS removed.
Recommended for checkout/cart and other heavily dynamic pages.

---

= 🖼️ Lazy Load Images =

**Enable Lazy Loading**
Default: off

Adds `loading="lazy"` to qualifying `<img>` tags found in post content, featured
images, widget text, and `<picture>` elements. For browsers that do not support the
native `loading` attribute, a small IntersectionObserver polyfill is injected into
`wp_footer` (activates only when native support is absent).

**Skip First N Images**
Default: 1

Images 1 through N are marked `loading="eager"` instead of `loading="lazy"`.
Set this to at least 1 to protect the hero/LCP image from being lazy-loaded, which
would hurt Core Web Vitals. Accepts 0–20.

**Exclude by CSS Class**
Default: empty

Comma-separated CSS class names. Any `<img>` that has one of these classes loads
eagerly (not lazily).

Example: `hero-image, no-lazy, skip-lazy`

**Exclude by Element ID**
Default: empty

Comma-separated element IDs. Any `<img>` with a matching `id` attribute loads
eagerly.

Example: `hero-banner, site-logo`

**Exclude by Filename**
Default: empty

Comma-separated partial filename strings. Any `<img>` whose `src` URL contains one
of these strings loads eagerly.

Example: `logo, hero, banner`

---

= 🗑️ Remove Unused Assets =

**Remove Emoji Scripts**
Default: off

Removes the WordPress emoji detection script, its inline stylesheet, and the
`s.w.org` DNS prefetch hint. Safe to enable if you use real Unicode emoji in your
content — browsers display them natively without the WordPress helper.

**Remove oEmbed / wp-embed**
Default: off

Removes the `wp-embed.js` script and the oEmbed discovery links added to `<head>`.
Also unregisters the oEmbed REST API endpoint. Disable only if you rely on
embedding your WordPress posts inside other websites.

**Remove Block / Gutenberg CSS**
Default: off

Dequeues `wp-block-library`, `wp-block-library-theme`, and `global-styles`
stylesheets on the front-end. Disable if your theme or content depends on
Gutenberg block styles.

**Disable WooCommerce Assets on Non-Shop Pages**
Default: off
Requires: WooCommerce active

Dequeues WooCommerce scripts and styles on pages that are unrelated to the shop,
cart, checkout, or account. WooCommerce assets are always kept on those pages.

---

= 📄 HTML Optimization =

**Minify HTML Output**
Default: off

Collapses redundant whitespace between HTML tags in the full page output. Content
inside `<pre>`, `<textarea>`, `<script>`, and `<style>` tags is always preserved
exactly as-is.

**Remove HTML Comments**
Default: off

Strips HTML comments (e.g. theme generator tags, plugin banners) from the page
output. Conditional IE comments are also removed.

**Remove Inline JS Comments**
Default: off

Removes `//` single-line and `/* … */` block comments from inline `<script>`
blocks in the HTML output. String literals and template literals are never
modified. License comments (`/*! … */`) are preserved.

**Remove Inline CSS Comments**
Default: off

Removes `/* … */` block comments from inline `<style>` blocks in the HTML output.

---

= 🚀 Browser Cache Headers (.htaccess) =

**Enable Browser Caching**
Default: off
Requires: Apache with mod_expires, mod_headers, mod_deflate. .htaccess writable.

Injects the following directives into the root `.htaccess` file inside a clearly
labelled block that is never duplicated:

* **Gzip compression** (mod_deflate) for HTML, CSS, JS, JSON, SVG, and fonts.
* **Brotli compression** (mod_brotli, Apache 2.4.26+) for the same types.
* **Expires headers** (mod_expires): 1-year cache for static assets; no-cache
  for HTML and API responses.
* **Cache-Control headers** (mod_headers): `max-age=31536000, public, immutable`
  for CSS, JS, images, and fonts.

Disabling this option or deactivating the plugin removes the injected block cleanly.

---

= 📦 JS File Minification & Cache =

**Minify JS Files**
Default: off
Requires: wp-content/uploads/bepluspb-cache/ directory to be writable

Minifies every enqueued JavaScript file using a safe character-by-character comment
stripper that correctly handles string literals, template literals, and URL protocol
slashes (`https://`). Already-minified `*.min.js` files and external CDN scripts are
skipped. Cache files use a 12-character content MD5 hash for automatic cache busting.

Lines are never joined to avoid breaking JavaScript's Automatic Semicolon Insertion
(ASI) rules.

**Cache Status**

Shows how many CSS and JS files are currently stored in the cache directory
(`wp-content/uploads/bepluspb-cache/`). Use the "Clear CSS/JS Cache" button to delete
all cached files; they are regenerated on the next page load.

The "Beplus Performance Booster" item in the WordPress admin bar (visible to administrators on
both the front-end and back-end) shows cache size and file count in a dropdown panel
and provides a one-click button to clear the cache.

---

= ☁️ CDN =

**Enable CDN**
Default: off

Rewrites matching static-asset URLs on this site to the CDN domain configured
below — enqueued CSS/JS, media library images (including responsive `srcset`),
matching URLs inside post content and widgets, and any other matching URL in
the rendered page (including root-relative paths written directly into theme
or page-builder markup). External URLs and already-CDN URLs are left untouched.
Like other front-end optimisations, this is skipped for logged-in administrators.

**CDN URL**
Default: empty

Your CDN pull-zone domain — for example, the hostname assigned when you add
this site as a CDN zone with a provider such as [QUIC.cloud](https://quic.cloud/)
(e.g. `https://xxxxxxxx.quic.cloud`), BunnyCDN, or KeyCDN, or a custom domain
CNAME'd to one. This is a generic pull-zone rewriter and works with any CDN
provider — it is not an official integration with any of them.

**File Types**
Default: `css,js,jpg,jpeg,png,gif,webp,avif,svg,ico,woff,woff2,ttf,otf,eot,mp4,webm,pdf`

Comma-separated file extensions. Only URLs ending in one of these are rewritten to
the CDN; everything else (PHP endpoints, REST/AJAX requests, admin URLs) is
untouched by design since the matching is extension-based.

**Exclude from CDN**
Default: empty

One URL keyword per line. URLs containing any of these strings stay on your own
domain instead of being rewritten to the CDN.

---

= Per-Page Cache Disable (Meta Box) =

Every post and page edit screen includes a "Beplus Performance Booster" meta box in the sidebar.
Checking **Disable CSS/JS cache optimizations for this page/post** stores the
`_bepluspb_disable_cache` flag in post meta. When set, the Minify CSS Files and Minify
JS Files features are bypassed for that specific URL and the original (unminified)
files are served instead. Useful for debugging or resolving conflicts on a specific
page without disabling minification site-wide.

= About BePlus =

This plugin is developed and maintained by BePlus,
a WordPress and Shopify development studio with 10+
years of experience building themes and plugins for
nonprofits and eCommerce brands.

Learn more at beplusthemes.com.

== Installation ==

1. Upload the `beplus-performance-booster` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Settings > Beplus Performance Booster** to configure each feature.

== Frequently Asked Questions ==

= Will this plugin slow down my admin panel? =
No. Every optimisation hook is guarded by `is_admin()` and an additional check that
skips users with the `manage_options` capability on the front-end.

= I see a flash of unstyled content (FOUC) after enabling Non-Render-Blocking CSS. =
Add a URL keyword for your theme's main stylesheet to the **Exclude CSS Files**
textarea. That file will be loaded normally while the rest are deferred.

= The Browser Cache option says my .htaccess is not writable. =
Your server's file permissions do not allow PHP to write to `.htaccess`. Set the
file to 644 (or ask your host) and try again, or add the rules manually.

= Where are the minified CSS/JS cache files stored? =
In `wp-content/uploads/bepluspb-cache/` — inside your `wp-content` directory. The directory
is created automatically with an `index.php` stub (to prevent directory listing) and
a `.htaccess` stub (to block direct PHP execution).

= Is WooCommerce required? =
No. The WooCommerce asset-removal option is only applied when WooCommerce is active.
All other features work independently.

= Can I use this plugin alongside a caching plugin? =
Yes. The .htaccess browser-caching rules complement full-page caches (LiteSpeed
Cache, W3 Total Cache, WP Super Cache, etc.). The JS/CSS optimisations operate at
the PHP output level and work with most caching setups.

= What happens to my settings when I delete the plugin? =
The `uninstall.php` script removes all plugin data: the `bepluspb_settings` option, the
injected `.htaccess` rules, every file in `uploads/bepluspb-cache/`, and the
`_bepluspb_disable_cache` post meta from every post.

= Who develops this plugin? =
This plugin is developed and maintained by BePlus, a WordPress and Shopify development studio. You can learn more about our work at beplusthemes.com.

== Screenshots ==

1. **Dashboard tab** — Master cache toggle, one-click recommended-settings panel, and cache statistics.
2. **Cache Files tab** — CSS and JS file minification options with cache-exclusion controls.
3. **Status tab** — Live system status report covering PHP, WordPress, server environment, and plugin settings.
4. **Admin bar panel** — Cache size and file count, colour-coded status dot, one-click Clear Cache button.

== Changelog ==

= 1.0.8 =
* Maintenance: `Requires PHP` raised to 8.1 (7.4 reached end-of-life in
  November 2022 and no longer receives security patches). No functional
  changes.

= 1.0.7 =
* Security (hardening): the internal URL-to-path resolver used by CSS/JS
  minification and Remove Unused CSS now canonicalizes candidate paths with
  `realpath()` and verifies they stay inside `wp-content`/site root, and only
  ever resolves genuine `.css`/`.js` asset URLs. Previously a crafted asset
  URL containing `../` could point the resolver at files outside the intended
  directory, or at a non-asset file inside the site root (e.g. `wp-config.php`)
  that could then be read and cached. Defence-in-depth: asset URLs come from
  registered themes/plugins, so exploitation required elevated access, but the
  resolver is now strict regardless.
* Reliability: the root `.htaccess` file is now backed up once
  (`.htaccess.bepluspb-bak`) before this plugin first inserts its
  browser-cache/compression rules, so the original can be restored if a write
  is ever interrupted.

= 1.0.6 =
* Fixed: Remove Unused CSS cache was not invalidated when a page's content
  changed (e.g. editing a post to add a shortcode/block whose CSS class was
  previously stripped out of the cached stylesheet). The cache only checked
  whether the *source* stylesheet file had changed, not whether the *page
  content* had — so a stale, over-trimmed CSS file could keep being served
  after a content edit until someone noticed broken styling and manually
  clicked "Clear Cache". Now automatically purged on: saving/publishing a
  post, a scheduled post going live, switching themes, and saving Customizer
  changes.

= 1.0.5 =
* Security: the Object Cache config file (`wp-content/.bepluspb_oc.json`), which
  can contain a plaintext Redis/Memcached AUTH password, is now blocked from
  direct HTTP access via an auto-generated `.htaccess` rule in `wp-content/`
  (Apache/LiteSpeed). Previously this file was served directly if requested.
  Sites on nginx should add an equivalent `location ~ /\.bepluspb_oc\.json { deny all; }`
  rule manually, since nginx does not read `.htaccess` files.
* Security: the Object Cache drop-in now unserializes cached values with
  `allowed_classes => false`, preventing PHP Object Injection if a cache
  value is ever read back in a tampered or unexpected state.

= 1.0.4 =
* Added Object Cache support: persistent caching via Redis or Memcached with a WP drop-in (wp-content/object-cache.php).
* New "Object Cache" settings tab: driver selection (Redis/Memcached), host/port, Redis AUTH password, Redis DB index, persistent connection, global groups, non-persistent groups.
* Connection test button with live AJAX result.
* Install/Remove drop-in buttons directly from the settings page.
* Config file (.bepluspb_oc.json) written to wp-content for zero-overhead bootstrap by the drop-in.
* Uninstall script now removes the object-cache drop-in and config file.
* Added "⚡ Enable All Recommended" button to the Dashboard tab — activates all recommended settings in one click (skips settings that require manual prerequisites, e.g. htaccess not writable).
* Status tab now includes a "PHP Extensions" panel showing availability of Redis, Memcached, OPcache, cURL, GD/ImageMagick, mbstring, OpenSSL, zlib, and intl.

= 1.0.3 =
* Added QUIC.cloud (or any pull-zone) CDN support: new "CDN" settings tab rewrites enqueued CSS/JS, media library images (incl. srcset), and matching content/widget URLs to a configured CDN domain.
* New options: Enable CDN, CDN URL, File Types, Exclude from CDN.

= 1.0.2 =
* Added Remove Unused CSS: per-URL cached stripping of unused CSS rules, with Unused CSS Selector Safelist and Unused CSS URL Excludes options.

= 1.0.1 =
* Added Delay Mode option (Simple / Advanced) for JS delay.
* Added JS Release Delay (ms): fallback timer that releases delayed scripts after above-fold images and fonts load; 0 = user interaction only.
* JS delay now applies to all users including logged-in administrators.
* Renamed "Image-Load Wait" setting to "JS Release Delay (ms)" for accuracy.

= 1.0.0 =
* Initial release.
* JavaScript delay (Simple / Advanced mode) and defer with per-script exclude list.
* JS Release Delay: fallback timer that releases delayed scripts after above-fold images and fonts load; `0` = user interaction only.
* CSS inline minification and non-render-blocking preload swap.
* CSS file minification with content-hash disk cache.
* JS file minification with safe comment stripper (respects string literals, ASI).
* Native lazy loading for `<img>` and `<picture>` elements.
* IntersectionObserver polyfill for lazy load in older browsers.
* Skip first N images (LCP protection), exclude by class / ID / filename.
* Remove emoji, wp-embed, Gutenberg block CSS, WooCommerce assets on non-shop pages.
* HTML minification and comment/JS-comment/CSS-comment stripping.
* Browser cache and gzip/brotli rules injected into .htaccess via insert_with_markers().
* Admin bar "Beplus Performance Booster" menu with cache size/file count panel and one-click clear.
* Per-page cache-disable meta box on all public post type edit screens.
* Uninstall script cleans up all options, rules, cache files, and post meta.

== Upgrade Notice ==

= 1.0.8 =
Requires PHP 8.1+ now (was 7.4, now end-of-life). No functional changes.

= 1.0.7 =
Security hardening for the CSS/JS path resolver (realpath + extension
allowlist) and automatic .htaccess backup before first write. Recommended.

= 1.0.6 =
Fixes stale CSS after editing content when "Remove Unused CSS" is enabled.
Recommended update for anyone using that feature.

= 1.0.5 =
Security fix: protects the Object Cache config file from direct HTTP access
and hardens the object-cache drop-in against PHP Object Injection.
Recommended update for anyone using the Object Cache feature with Redis or
Memcached.

= 1.0.4 =
New Object Cache tab, "Enable All Recommended" button, and PHP Extensions panel in Status. Object cache is off by default — install the drop-in from the settings page to activate.

= 1.0.3 =
New CDN tab: rewrite static-asset URLs to a QUIC.cloud (or other) CDN domain. Off by default — no upgrade steps required.

= 1.0.2 =
New Remove Unused CSS tab: per-URL cached stripping of unused CSS rules. Off by default — no upgrade steps required.

= 1.0.1 =
New JS delay options: Delay Mode (Simple/Advanced) and JS Release Delay (ms). No upgrade steps required.

= 1.0.0 =
Initial release — no upgrade steps required.
