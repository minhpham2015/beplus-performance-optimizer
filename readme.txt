=== Beplus Performance Booster ===
Contributors: bearsthemes, minhphamit
Tags: performance, lazy load, cache, minify, optimization
Requires at least: 5.0
Tested up to: 7.1.1
Stable tag: 1.3.4
Requires PHP: 8.2
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
* WordPress Core-managed image loading policy with targeted exclusions
* Remove emoji, wp-embed, Gutenberg CSS, WooCommerce assets on non-shop pages
* HTML minification and comment stripping
* Browser cache and gzip/brotli rules via .htaccess
* Per-page option to disable the cache for specific posts/pages
* Custom CDN (pull-zone) URL rewriting for static assets
* Automatic WebP/AVIF image serving when a matching file already exists
* Cloudflare integration: one-click cache purge, zone auto-detection via API
  Token, and Development Mode toggle from wp-admin
* Critical CSS per page type, generated in your own browser, so stylesheets can load after the first paint without a flash of unstyled content
* Priority Images (LCP): preload and top priority for the hero image, plus an "Analyze LCP" tool in the admin bar that finds it for you
* Google Fonts: font-display and preconnect, or download the fonts once and serve them from your own site

Full settings reference (every option, default, and what it does) has moved out of this readme to keep the Description section within WordPress.org's import limits: https://github.com/minhpham2015/beplus-performance-optimizer/blob/master/docs/SETTINGS_REFERENCE.md

Credits: the Advanced "Delay JS" runtime (`assets/js/delay-advanced.js`) is derived from WP Meteor by Aleksandr Guidrevitch (https://wordpress.org/plugins/wp-meteor/), licensed GPLv3 or later, and modified for this plugin. Because of it the plugin as a whole is distributed under GPLv3 or later terms (GPLv2-or-later code may be combined with GPLv3-or-later code).

== Installation ==

1. Upload the `beplus-performance-booster` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Settings > Beplus Performance Booster** to configure each feature.

== Frequently Asked Questions ==

= Will this plugin slow down my admin panel? =
No. Every optimisation hook is guarded by `is_admin()` and an additional check that
skips users with the `manage_options` capability on the front-end.

= I see a flash of unstyled content (FOUC) after enabling Non-Render-Blocking CSS. =
Your theme's own stylesheets (including those in sub-folders such as `assets/css/`) are kept render-blocking automatically, but plugin and page-builder stylesheets are deferred. To remove the flash without giving up the speed, turn on **Critical CSS** (Cache Files tab → Critical CSS): it prints the CSS the first screen needs and defers the rest. Otherwise add a URL keyword for the stylesheet that causes the flash to the **Exclude CSS Files** textarea; that file will be loaded normally while the rest are deferred.

= Does self-hosting Google Fonts make my server contact Google? =
Yes, but only when you choose "Self-host", and only your server (never your visitors' browsers): a few requests to fonts.googleapis.com and fonts.gstatic.com download the fonts your pages already use, once. See "External services" below.

= Why does my font still load from Google after I chose Self-host? =
The first time a logged-out visitor opens a page, the plugin only notes which Google Fonts it uses; a background WP-Cron job downloads them a few seconds later. Open one page in a private window and press "Download now" in the Google Fonts card to do it at once. If a download fails, the page keeps the Google link and the card shows the reason.

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

== External services ==

This plugin connects to outside services only in the two cases below. Both are optional and off by default, and neither runs on a visitor's page load.

**Google Fonts (only when "Google Fonts → Self-host" is selected)**
Your server downloads the Google Fonts stylesheets your own pages already use from fonts.googleapis.com and the font files they name from fonts.gstatic.com, once, in the background (WP-Cron) or when you press "Download now". The requests are made over HTTPS by your server with a generic browser User-Agent; they contain only the font stylesheet or font file being requested, no visitor data and no information about your site. After that your pages link the local copies. Google Fonts terms and privacy information: https://developers.google.com/fonts/terms and https://developers.google.com/fonts/faq/privacy

**Cloudflare (only when you enter an API token under "Cloudflare")**
When you press Test Connection, purge the cache or toggle Development Mode (or clear the plugin's cache with Cloudflare purging enabled), your server calls the Cloudflare API (api.cloudflare.com) with your API token to look up your zone and perform that action. Nothing about your visitors is sent. Cloudflare terms and privacy policy: https://www.cloudflare.com/website-terms/ and https://www.cloudflare.com/privacypolicy/

The optional Page Cache Preload only requests your own site's pages.

== Screenshots ==

1. **Dashboard tab** — Cache stats, master cache toggle, and the one-click recommended-settings panel.
2. **Cache Files tab (Page Cache)** — Full-page caching controls: lifespan, bypass cookies, mobile handling, and per-visitor exclusions.
3. **Status tab** — Live system status report covering PHP, WordPress, server environment, and actionable recommendations.
4. **Cloudflare tab** — API token setup, one-click cache purge, and Development Mode toggle without leaving wp-admin.
5. **Cleanup tab** — Remove unused assets (emoji scripts, oEmbed, Gutenberg CSS) and HTML output optimization.
6. **Advanced tab** — Font preload hints and a custom CDN (pull-zone) URL rewriter for static assets.
7. **Predictive Navigation tab** — Core speculation-rules integration with Safe/Balanced/Fast modes and path exclusions.
8. **Object Cache tab** — Redis/Memcached connection settings, persistent connections, and global cache groups.

== Changelog ==

= 1.3.4 =
* Fix: Delay JS (Simple mode) now replays `DOMContentLoaded` and `load` for scripts that run late, so Contact Form 7 and similar forms work instead of reloading the page on submit.
* New: Delay JS never delays cookie/consent banners, Elementor's lazy-background script, "no-js" class switches or other lazy-load scripts, and respects `data-no-optimize`, `data-no-defer`, `data-no-delay` and `data-cfasync="false"`. "Exclude JS Files" now also keeps a script out of Minify JS.
* New: Delay JS (Advanced) plays Elementor entrance animations of the first screen on load instead of after the first interaction.
* New: JS Release Delay presets, and `?bepluspb-nodelay` in a URL loads that page with Delay JS off for comparing or debugging.
* New: HTML lazy load handles iframes (native lazy loading), loads every slide of a slider/carousel, has a "Load Images Early (px)" margin, and can lazy load inline background images (off by default).
* New: images without width/height keep their real proportions in the lazy-load placeholder (no page jump), loaded images get the class `lazyloaded` and fire a `lazyloaded` event, and a notice appears when another lazy-load plugin is active.
* Fix: HTML lazy load no longer breaks an `<img>` written inside an attribute value, and Delay JS (Advanced) leaves scripts inside `<noscript>`, `<template>`, `<textarea>` and comments alone.
* Change: Recommended Settings and the Status tab show option names as the settings screens do.
* Credits: the Advanced Delay JS runtime is derived from WP Meteor (GPLv3 or later).

= 1.3.3 =
* Fix: "Minify JS Files" no longer corrupts scripts that contain a regular expression ending in `\//` (for example Contact Form 7's `index.js`: the form stopped working and the page reloaded on submit). Already-minified one-line bundles are now left alone. Press "Purge ALL Cache" once after updating so the broken copies are rebuilt.
* New: "Lazy Load Method" in the Media settings. "Rewrite the page HTML" lazy-loads every image on the page, including images printed by Elementor and other page builders and `<picture>` sources, with a tiny placeholder and a small script that loads each image about 300 px before it is seen. The first images, eager/high-priority images and the `skip-lazy` / `no-lazy` classes are skipped, and it falls back to the browser's native loading when a Content-Security-Policy blocks inline scripts. The default stays "WordPress Core". Turn off other lazy-load plugins when you use it.
* Change: Elementor Pro's popup stylesheet (printed at the end of the page) no longer blocks rendering when Non-Render-Blocking CSS is on, or Critical CSS is on and the page has an entry.

= 1.3.2 =
* Fix: the Critical CSS Generator no longer saves the admin toolbar's page offset (an empty 32px band above the page for visitors). If you generated Critical CSS with 1.3.0 or 1.3.1, generate it again and save.

= 1.3.1 =
* Change: Critical CSS now works on its own. A page with saved Critical CSS prints it and loads every stylesheet after the first paint whether or not "Non-Render-Blocking CSS" is on; pages without an entry behave as before.
* New: the Critical CSS Generator analyses four screen sizes (phone, tablet, desktop, large screen) and, by default, also the area just below the first screen, so big monitors, tablets and a small scroll no longer show unstyled elements. After updating, generate your Critical CSS again.
* Fix: the Critical CSS Generator now says why an analysis failed (slow page, blocked frame, redirect, script error), works on slow connections and on pages where the footer script is delayed, and offers "Analyse this window only" as a fallback and a copyable technical report.
* Fix: the Critical CSS Generator and LCP Analyzer panels no longer scramble text on right-to-left sites.
* Fix: Google Fonts settings: the note about Self-host is no longer red and the font-display labels are no longer cut off.

= 1.3.0 =
* New: Critical CSS. Print the CSS the first screen needs in the page head and load every stylesheet after the first paint, with no flash of unstyled content. Generate it from the admin bar ("Generate Critical CSS"): the page is analysed in your browser on a phone-sized and a desktop-sized copy and saved per page type (front page, posts, pages, archives…; right-to-left pages separately). Or paste CSS by hand. Off by default; needs Non-Render-Blocking CSS.
* New: Priority Images (LCP). Tell the browser which image is the Largest Contentful Paint of a page: it is preloaded with high priority and no longer lazy-loaded. The new "Analyze LCP" tool in the admin bar finds the LCP element for mobile and desktop, explains what is wrong with it and fills Priority Images for you.
* New: Google Fonts. "Optimize" adds font-display and preconnect to Google Fonts links. "Self-host" downloads the fonts once to your own site (background download, or "Download now") so visitors' browsers no longer contact Google; until a font is downloaded the page keeps the Google link. Off by default. See "External services".
* New: with a CDN, the plugin now opens the connection to the CDN host early (preconnect).
* Fix: Non-Render-Blocking CSS no longer makes themes flash unstyled. All stylesheets inside your theme and parent theme folders (not only style.css) stay render-blocking unless Critical CSS covers the first paint.
* Fix: the Page Cache stayed off on multilingual sites that use one domain or subdomain per language (WPML, Polylang): it kept saying the site address had changed and cached nothing. After updating, press Save once on the Page Cache settings.
* Change: the readme now lists the external services (Google Fonts self-hosting, Cloudflare).

= 1.2.2 =
* New: Recommended Settings now recognises Elementor and recommends keeping its stylesheets render-blocking and leaving Remove Unused CSS, Inline All CSS and Delay JS off, because Elementor optimises its own CSS/JS. It is layered on top of your site profile (an Elementor shop keeps the WooCommerce plan).
* Fix: Remove Unused CSS no longer breaks pages as easily. Classes that scripts add later (open menus, sliders, pop-ups, cookie banners, WooCommerce notices) are kept automatically, and the stylesheet is built from everything seen on a page over several visits instead of only the first one. Fonts and images referenced by relative paths keep working, and nothing is built from error, maintenance or incomplete pages.
* Fix: Minify CSS and Inline All CSS now rewrite relative url() and @import paths, so fonts and background images no longer break after the file moves to the cache folder.
* Fix: the Page Cache no longer stores a page that still links the full stylesheets while Remove Unused CSS is building its trimmed copy, so the lighter CSS is actually used.
* Fix: Non-Render-Blocking CSS keeps your theme's main stylesheet blocking even with Minify or Remove Unused CSS on, leaves print/conditional-media and already-async stylesheets alone, and switches itself off when a Content-Security-Policy would block it.
* New: Inline All CSS skips Elementor stylesheets by default ("Skip Elementor CSS" option), because Elementor caches and optimises its own CSS. Inlined stylesheets also keep their original id.

= 1.2.1 =
* Fix: a site could crash completely (including wp-admin, "Cannot redeclare wp_cache_init()") after you turned Object Cache off and saved, or when its hidden settings file was missing. The Object Cache drop-in now steps aside and WordPress uses its normal cache. If you use Object Cache, open the Object Cache tab and press "Install Drop-in" once to update it.
* Fix: saving settings with Object Cache off now removes an outdated drop-in first, and uninstalling no longer deletes the Object Cache settings while a drop-in is still installed.
* Fix: Delay JS - a form submitted while delayed scripts are still loading is held and sent once they have run (no double submit), and scripts that other scripts depend on through inline "after" code are no longer moved out of order by Delay JS or Defer.

= 1.2.0 =
* New: optional Page Cache Preload (off by default, Cache Files tab → Page Cache). After the cache is cleared, WordPress cron visits your home page and up to 500 recently updated public pages in the background, a few at a time, so the first visitors get fast cached pages. Only your own site is contacted; it stops by itself if requests fail, runs at most once every 10 minutes, and rebuilds expired pages automatically.
* Change: the Page Cache lifespan is now limited to 1–24 hours (was 72). Longer lifespans could leave forms on cached pages with expired security tokens. Higher saved values are lowered to 24.

= 1.1.14 =
* New: optional Page Cache (off by default, Cache Files tab). Visitors who are not logged in get a stored HTML copy of public pages, served before WordPress loads the theme. Automatically skipped for logged-in users, carts/checkouts, forms and personalised pages; cleared when you edit content, menus, widgets, themes or these settings. Separate mobile copies are kept only for pages that need them. "Purge ALL Cache" now empties it too.
* New: admin bar "Purge ALL Cache" is a one-click button (with a confirmation) that returns you to the page you were on and shows the result on screen. The admin bar Object Cache purge button now also asks for confirmation on every screen.
* Fix: the admin bar "Purge ALL Cache" link could open the wrong settings tab and hide the button.
* Fix: Remove Unused CSS cache was not cleared when you saved a post or switched theme; deleted CSS/JS files no longer leave cached pages pointing at missing files.
* Fix: administrators now see the original CSS/JS (like the other optimisers) while other logged-in users get the minified files. The "Enable Cache for Logged-In Users" option was removed because it never affected anything else.
* Fix: .htaccess browser-cache rules no longer override an app's own Cache-Control, only mark versioned CSS/JS as immutable, and no longer cover feeds or robots.txt (re-save settings to refresh the rules).
* Fix: Delay JS no longer breaks inline scripts; HTML/CSS/CDN transforms fall back to the untouched page instead of a blank page on very large pages; defer JS skips scripts with inline code that must run after them; page-builder editors, AMP and the Customizer preview are left alone.
* Fix: Object Cache drop-in handles atomic add, increment/decrement, cached false values and a failed pipeline correctly, and uses fewer round trips (batched reads and writes).
* Improvement: faster CSS/JS minify cache lookups, cached inline CSS and a lighter CDN WebP/AVIF check.

= 1.1.13 =
* Security: block web access to the object-cache drop-in backup folder (backups of another plugin's drop-in can contain cache passwords) and use unguessable backup names.
* Security: stop a stylesheet/script symlink from exposing wp-config.php through the minified-file cache.
* Security: never save an Object Cache config containing a password unless the file's access protection is confirmed; show a clear error otherwise.
* Fix: replacing or restoring an object-cache drop-in now works on PHP-FPM hosts (a real PHP CLI is used for validation) and explains clearly when it cannot run.
* Fix: clearing the Object Cache no longer wipes other sites or apps sharing the same Redis/Memcached server; only this site's entries are removed.
* Fix: CSS minification no longer changes selectors such as ".a :hover" or text inside quoted strings.
* Improvement: admin code reorganized into smaller files for easier maintenance (no change in behavior), plus broader automated tests and real WordPress + Redis checks.

= 1.1.12 =
* Display boolean values in the Recommended Settings preview as Active and Inactive instead of raw 1 and 0, without changing stored values or behavior.
* Use one exact machine-readable identity across the shipped Object Cache drop-in, ownership checks, and health probes; reject foreign or mismatched drop-ins.
* Harden object-cache replacement and restoration with path/symlink checks, non-executable verified backups, authenticated restore manifests, transaction locking, retention protection, metadata restoration, isolated health checks, and automatic rollback.
* Write Object Cache configuration before installation and correct replacement/settings-tab interactions.

* Merge Fonts, CDN, and Cache Exclusions into one Advanced tab without changing saved settings. Existing deep links continue to work; Cloudflare, Predictive Navigation, and Object Cache remain separate tabs.

* Add administrator-only preflight, checksum-verified backup-and-replace, and rollback-capable restore for conflicting object-cache drop-ins.

* Reorganize Dashboard Cache Actions into clearer optimization, generated-cache, and object-cache groups with disk metrics, availability status, compact actions, and responsive layout.

* Move the shared, POST-only Object Cache purge control to the Dashboard and Admin Bar, removing the duplicate Object Cache tab action while retaining settings and health tools.

* Add POST-only Purge ALL Cache with structured disk and Cloudflare results.
* Add separately confirmed, fail-closed Object Cache purge controls. Object Cache remains excluded from Purge ALL.
* Add an integration hook for third-party page-cache purges.

* Harden Font Preload v2 origin deduplication with effective ports and render-time revalidation of filtered entries, and correct the related admin guidance.

* Add fail-closed Font Preload v2 URL validation, diagnostics, registry/filter support, and conservative CDN handling.

* Correct Lazy Load compatibility: configurable Core attribute policy starts on WordPress 6.4; WordPress 5.5–6.3 retains native Core behavior.
* Add deterministic WordPress 6.3/6.4 compatibility coverage and automatic CI execution of every standalone test.

* Replace regex image rewriting and its JavaScript fallback with WordPress Core-managed loading optimization, targeted exclusions, safe thresholds, request gates, and fail-open compatibility behavior.

* Add local-only, site-aware Recommended Settings with exact preview, safe atomic apply/disable, and one-time restore.

* Redesign Predictive Navigation settings with clearer status, accessible mode cards, exclusions, compatibility/privacy guidance, responsive styling, and unchanged saved settings and behavior.

* Add Predictive Navigation for WordPress 6.8+ through Core Speculation Rules, with safe/balanced/fast modes, commerce and sensitive-path exclusions, sanitized custom paths, and no telemetry or external calls. Disabled by default.

* Fix: an internal diagnostic log message related to the 1.0.10 buffer-order
  fix could write to the PHP error log even when debug logging was not
  enabled. It now only logs when the site owner has explicitly turned on
  WordPress debug logging (WP_DEBUG_LOG). No visible behavior change for
  normal site operation.

= 1.0.10 =
* Fix: Delay JS (Advanced mode) could silently do nothing when Remove
  Unused CSS was also enabled. Both features open a nested PHP output
  buffer; Remove Unused CSS's shutdown handler used a weak check that
  could close Delay JS's still-open inner buffer first and discard its
  rewritten output before the real page content was delivered — sites
  received the original, un-delayed scripts even though the setting was
  on. Both buffers now close in the correct order and Remove Unused CSS
  verifies it is closing exactly its own buffer before doing so.
* Fix: Delay JS (Simple mode) now preserves a script's original `type`
  (e.g. `module`) plus `integrity`, `crossorigin`, `nonce`, and
  `referrerpolicy` attributes when the script is restored after user
  interaction. Previously these were dropped, which could break ES
  module scripts and scripts protected by Subresource Integrity or a
  nonce-based Content-Security-Policy.

= 1.0.9 =
* New: "Cloudflare" settings tab — connect a Cloudflare zone with an API
  Token (auto-detects the matching zone for this site's domain via "Test
  Connection"), purge the Cloudflare edge cache with one click (optionally
  in sync with the plugin's own Clear Cache button), and turn Cloudflare
  Development Mode on/off/check status without leaving wp-admin. All calls
  are admin-triggered only and rate-limited; nothing runs on a normal
  front-end page load.
* New: optional "Serve WebP/AVIF Images" toggle in the CDN tab. When
  enabled, JPG/PNG image URLs are swapped for a same-named .avif or .webp
  file when one already exists next to it and the visitor's browser
  declares support for that format. Prefers AVIF over WebP when both are
  supported and both files exist. This plugin does not generate or convert
  any images itself — it only rewrites the URL when a matching file is
  already present on disk (e.g. created by WordPress core, a theme build
  step, or another optimization tool). Off by default.
* Fixed: Object Cache (Redis) reported "connected" in the settings page
  but was silently never actually being used — a scoping bug meant the
  saved config never reached the connection code. Object Cache now
  actually connects and caches.
* Fixed: two crashes that could bring down wp-admin (white screen) on
  sites using the Object Cache: caching the logged-in user (`users` group)
  or the update-count indicator (`site-transient` group) through Redis
  could corrupt on read-back. Both groups are now kept out of the
  persistent Redis cache. **If you already had Object Cache enabled**,
  clear/flush your Redis cache once after updating to remove any
  already-corrupted entries.
* Fixed: `install_dropin()` no longer installs the Object Cache drop-in
  on a site with no valid saved configuration, which could previously
  crash the entire site.

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

= 1.3.4 =
Delay JS now keeps consent banners and Elementor's background script running at once, fixes forms that reloaded the page in Simple mode, and plays Elementor entrance animations on load. HTML lazy load covers iframes and sliders.

= 1.3.3 =
Fixes "Minify JS Files" breaking scripts such as Contact Form 7 (press "Purge ALL Cache" once after updating) and adds an optional HTML lazy-load method that works with Elementor.

= 1.3.2 =
Fixes an empty band above the page caused by Critical CSS generated while logged in. If you use Critical CSS, generate it again and save after updating.

= 1.3.1 =
Critical CSS no longer needs Non-Render-Blocking CSS, and its generator covers more screen sizes. If you use Critical CSS, generate it again after updating.

= 1.3.0 =
Adds Critical CSS, Priority Images with an LCP Analyzer, and Google Fonts optimisation/self-hosting (all off by default). Non-Render-Blocking CSS now keeps your whole theme folder's CSS render-blocking (fixes the flash of unstyled content). Multilingual sites with one domain per language (WPML/Polylang): press Save once on the Page Cache settings after updating.

= 1.2.2 =
Makes Remove Unused CSS, Minify CSS, Inline All CSS and Non-Render-Blocking CSS much safer (fonts/images, JavaScript-toggled classes, theme stylesheet, CSP). Inline All CSS now skips Elementor stylesheets by default. Used-only CSS files are rebuilt automatically after updating.

= 1.2.1 =
Fixes a site-wide crash that could follow turning Object Cache off. If you use Object Cache, press "Install Drop-in" once on the Object Cache tab after updating.

= 1.2.0 =
Adds optional Page Cache Preload (off by default) that rebuilds the cache in the background after it is cleared. The Page Cache lifespan is now capped at 24 hours; a higher saved value is lowered automatically.

= 1.1.14 =
Adds an optional, off-by-default Page Cache, a one-click confirmed "Purge ALL Cache" in the admin bar, and many reliability fixes (Remove Unused CSS cache, .htaccess cache headers, Delay JS, Object Cache drop-in). Administrators now see the original CSS/JS; other logged-in users get the minified files. If you use Object Cache, the drop-in is updated: reinstall or update it from the Object Cache tab.

= 1.1.13 =
Security and reliability update: protects drop-in backups and the minified-file cache from exposing sensitive files, makes Object Cache replacement work on PHP-FPM hosts, and stops cache purges from affecting other sites sharing Redis/Memcached. If you use Object Cache with other sites on the same Redis server, set a unique WP_CACHE_KEY_SALT per site.

= 1.0.9 =
New Cloudflare integration (cache purge, Development Mode toggle) and
optional WebP/AVIF image serving on the CDN tab (both off by default). Fixes
critical Object Cache bugs: Redis was silently never connecting despite
reporting success, and caching the logged-in user or update-count indicator
through Redis could crash wp-admin. If you already had Object Cache
enabled, flush your Redis cache once after updating.

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
