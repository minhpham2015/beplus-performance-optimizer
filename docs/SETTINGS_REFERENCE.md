# Settings Reference

Full settings documentation for Beplus Performance Booster, moved out of
readme.txt (2026-11-17) because WordPress.org's readme parser does not
recognize custom section headers (only Description/Installation/FAQ/
Screenshots/Changelog/Upgrade Notice/etc. are standard) — a non-standard
'== Settings Reference ==' header caused everything up to the next
standard header to be folded into Description, pushing it past the
2,500-word limit and triggering an import truncation warning.

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

*JS Release Delay* (Advanced mode) — when delayed scripts are released without waiting
for the visitor. Presets: *Only after the first interaction* (value `0`, best Lighthouse
score), *Soon after the page has loaded* (100 ms), *2 seconds* / *5 seconds after the
page has loaded* (counted from the moment above-fold images and fonts have finished
loading), or a custom value in ms (up to 10000). With `0`, anything that has to run for
a visitor who only reads (analytics, tag manager, chat widgets, ads) starts at the first
scroll, click or key press, so visitors who leave without touching the page are not
counted: pick a timed release if you rely on that.

*Switching Delay JS off for one request* — add `?bepluspb-nodelay` to a URL to load that
page with Delay JS off (compare before/after, or find out whether it breaks something).
Pages with this parameter are never page-cached. To switch it off for whole pages, return
`false` from the `bepluspb_delay_js_enabled` filter (for example
`add_filter( 'bepluspb_delay_js_enabled', fn( $on ) => is_page( 'checkout' ) ? false : $on );`).

**Defer Non-Critical JS**
Default: off

Adds the `defer` attribute to every non-excluded `<script>` tag so scripts are
fetched in parallel and executed after the HTML is parsed. jQuery and
jquery-migrate are always protected from deferral.

**Exclude JS Files**
Default: empty

One URL keyword per line. Any script whose `src` attribute contains a listed
keyword is excluded from Delay, Defer and Minify JS. Use this to protect scripts that
must run immediately (e.g. analytics that need to capture the first page view).

Examples: `jquery`, `woocommerce`, `my-critical-script`

**Never delayed (built in)**

Whatever you list above, Delay JS never delays: cookie/consent banners (Complianz,
Cookie Notice, CookieYes, Cookiebot, OneTrust, Iubenda, Borlabs…), Elementor's
lazy-background script (without it, container background images stay hidden until
the first interaction), small scripts that switch the `no-js` class to `js`, other
lazy-load scripts (Rocket, lazysizes, Smush, Jetpack), and any script carrying
`data-no-optimize`, `data-no-defer`, `data-no-delay`, `data-cfasync="false"`,
`data-pagespeed-no-defer` or `nowprocket`. Developers can extend the list with the
`bepluspb_delay_js_exclude` filter.

In **Simple** mode, scripts that run after the page has loaded still get the
`DOMContentLoaded` / `load` listeners they register (for example Contact Form 7), called
once, in order, after every delayed script has run.

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

= 🧩 Critical CSS =

**Use Critical CSS**
Default: off
Works on its own: it does not need Non-Render-Blocking CSS

Prints the CSS the first screen needs in the page head and loads every
stylesheet (your theme's included) after the first paint, so the page is drawn at
once without a flash of unstyled content. The CSS is stored per page type (front
page, blog index, each post type, archives, search, other pages, and a default for
anything else; right-to-left pages have their own set), not per URL, so one entry
serves every page of that type and every language.

**Generate Critical CSS**
Open a page of your site while logged in as an administrator and choose
Beplus Performance Booster → "Generate Critical CSS (this page type)" in the admin
bar. The page is analysed on phone, tablet, desktop and large-screen sized copies (and a little below the first screen) and the
result is saved for that page type. Everything runs in your browser; nothing is
sent anywhere except to your own site when you press Save. You can also paste CSS
by hand in the Critical CSS card, and delete saved entries there.

Not used while "Inline All CSS" is on, or when a Content-Security-Policy forbids
inline styles. Administrators always see the original page, so check the result in
a private window.

---

= 🏁 Priority Images (LCP) =

**Priority Images**
Default: empty (Media card)

The image that is the Largest Contentful Paint of a page (usually the hero banner),
one per line: `image URL | home, all or /page-path/ | mobile, desktop or all`. The
browser is told to download it first (preload with high priority) and WordPress stops
lazy-loading it. The list can hold up to 100 lines (one per page or page type, e.g.
`/img/banner.jpg | single-post`), but one page preloads at most the first 4 lines that
match it, because more would compete with the LCP image itself. *Where* can be `home`,
`all`, a page type (`single`, `single-post`, `single-page`, `single-<post type>`,
`archive`, `blog`, `search`) or a page path. Use the exact URL the browser downloads for that
page.

**Analyze LCP (admin bar)**
Open a page while logged in as an administrator and choose
Beplus Performance Booster → "Analyze LCP (this page)". The plugin finds the LCP
element for mobile and desktop in your browser, tells you what is wrong with it
(lazy-loaded, no priority, found late through CSS, or text instead of an image) and
can add the image to Priority Images for you.

---

= 🧩 Automatic Exclusions (Advanced tab) =

**Exclude automatically** — Default: on

When a listed plugin or theme is active, its files are kept out of minify, defer, delay and CSS deferral, and its
checkout-style pages out of the page cache, as if you had typed them into the Exclude boxes. Your own Exclude boxes
are never changed. The list ships with the plugin (nothing is downloaded). Rules: WooCommerce, Elementor, Divi / Extra /
Divi Builder, Slider Revolution, Gravity Forms, and payment/CAPTCHA scripts (Stripe, PayPal, reCAPTCHA, hCaptcha,
Turnstile; always on). The card shows which rules were found on your site; tick **Skip** on a rule you do not need
(excluded files load a little slower than optimised ones).

---

= 🧪 Safe Testing (Cache Files tab) =

**Administrators only** — Default: off

* On: only logged-in administrators get the front-end optimizations; visitors and other users see the site as if the
  plugin did nothing. The Page Cache is paused and emptied while it is on. Untick and save when you are happy.
* Not covered: `.htaccess` browser-cache rules, Object Cache, Cloudflare, Predictive Navigation, Heartbeat control.

*One request without the optimizations* — add `?bepluspb_off=1` to any front-end URL (or use "View without
optimizations" in the admin bar). Works for everyone, nothing is saved, the page is never cached.

= 🎚️ Recommended Settings levels (Dashboard) =

Pick the site profile and a level, preview the exact changes, apply. "Restore Previous Settings" undoes the last apply once.

* **Safe**: caching, lazy load, CSS minify, emoji/embed cleanup. No JS minify or defer, no HTML minify.
* **Balanced** (default): the recommended plan of the profile.
* **Switches**: under the plan, every option the plan manages has its own Active/Inactive switch that saves at once
  (AJAX). Risky options show a warning. Switching by hand does not change the "Restore Previous Settings" snapshot.
* **Strong**: Balanced plus Non-Render-Blocking CSS and Delay JS (not on shops and membership sites). Page-builder
  safety rules (Elementor) still apply at every level.

---

= 💓 Heartbeat Control (Cleanup tab) =

Default: *WordPress default* for every location. Works without the master cache switch.

* **Dashboard**: every wp-admin page except the editing screens. *Reduce frequency* or *Disable*.
* **Post editor, site editor, widgets and Customizer**: *Reduce frequency* only. These screens use the
  Heartbeat for autosave, post locking and the session check, and the block editors need the script to load.
* **Front end**: *Reduce frequency* or *Disable*. Check plugins that show live data (notifications, forms, carts)
  after disabling.
* **Interval when reduced**: 15-120 seconds (a limit of WordPress). A longer interval already set elsewhere is kept.

= 🔗 Resource Hints (Advanced tab) =

Default: empty (nothing printed).

* **Preconnect**: up to 4 origins, one per line (`https://cdn.example.com`). Add `crossorigin` after the origin for
  fonts and other CORS requests. Each open connection costs the visitor bandwidth and battery: list only servers
  the first screen needs.
* **DNS-prefetch**: up to 10 origins. Only resolves the name, cheaper than preconnect.
* Only http(s) origins are kept (path and query are dropped); IP addresses, credentials and single-label hosts are
  rejected with a notice. The site's own address is skipped. The plugin never contacts these servers itself.

---

= 🔤 Google Fonts (Advanced tab) =

**Optimize Google Fonts**
Default: off

* *Optimize*: adds `font-display` (swap, fallback or optional) to Google Fonts links
  and `@import` rules in the page head that do not set one, and adds preconnect hints.
  Icon fonts (Material Icons, Font Awesome) and a `font-display` you already set
  are left alone. Nothing is downloaded.
* *Self-host*: downloads the stylesheets and font files your pages use once to
  `wp-content/uploads/bepluspb-cache/fonts/` and links those copies, so your visitors'
  browsers no longer contact Google (privacy / GDPR) and there is one origin less to
  connect to. Until a stylesheet is downloaded, the page keeps the Google link, so
  nothing breaks. "Download now" and "Delete local fonts" are in the same card.
  See "External services" below for exactly what is requested.

Not covered: fonts loaded by JavaScript (such as WebFont Loader) or printed after the head.

---

= 🖼️ Lazy Load Images =

**Enable Lazy Loading**
Default: off

Uses WordPress Core's native media loading policy without rewriting HTML or adding
a JavaScript fallback. On WordPress 6.4 or newer, the threshold and exclusion
settings below adjust Core-generated loading attributes. WordPress 5.5 through 6.3
retains its native behavior because Core does not expose that attribute filter.

**Lazy Load Method**
Default: WordPress Core

"WordPress Core" is described above. "Rewrite the page HTML" lazy-loads every image
of the page, including the ones printed by Elementor and other page builders, themes
and widgets, and `<picture>` sources: each image gets a tiny placeholder that keeps
its size, and a small inline script loads it about 300 px before it is seen. A
`<noscript>` copy keeps the images visible without JavaScript. Use it instead of
other lazy-load plugins (turn those off). When a Content-Security-Policy blocks inline
scripts, the page falls back to the browser's native `loading="lazy"`. CSS
background images are not changed (inline ones: see *Lazy Load Background Images*).

* An image without `width`/`height` keeps its real proportions in the placeholder
  (taken from the `-300x200` suffix of WordPress sizes or the file header of a file
  in the uploads folder), so the page does not jump when it loads. Filter:
  `bepluspb_lazy_html_image_size`.
* Each loaded image gets the class `lazyloaded` and fires bubbling `lazyloaded` and
  `bepluspb-lazyloaded` events, for masonry/gallery scripts that must re-measure.
* A script that reads an image's `src` as soon as the page starts (some lightboxes)
  sees the placeholder: give those images the class `skip-lazy`.
* A notice appears next to the setting when another lazy-load plugin is active
  (filter `bepluspb_lazy_conflicting_plugins`).

**Skip the First Images**
Default: 2

Only for "Rewrite the page HTML": the first N images of the page stay normal so the
top of the page shows at once. Images with `loading="eager"`, `fetchpriority="high"`
(Priority Images) or the class `skip-lazy` / `no-lazy` are always skipped.

**Lazy Load Iframes**
Default: on

Only for "Rewrite the page HTML": embeds (YouTube, Google Maps, forms) get the
browser's native `loading="lazy"`. Left alone: iframes that already set `loading`,
hidden or tracking frames (0x0, 1x1, `display:none`), `srcdoc`/`about:blank` frames,
the class `skip-lazy` / `no-lazy`, and other lazy loaders' markers.

**Lazy Load Background Images**
Default: off

Only for "Rewrite the page HTML": an element with an inline
`style="background-image:url(...)"` gets the image only when it comes near the screen
(same margin as images). Only a plain `background-image` declaration with one URL of
ordinary characters is handled (not the `background` shorthand, gradients, several
layers, `!important` or backgrounds set in CSS files; Elementor lazy loads its own).
The first N set in *Skip the First Images* stay normal, and `skip-lazy` / `no-lazy` /
`data-no-lazy` are honoured. Without JavaScript a `<noscript>` rule puts them back.
Off by default because a hero banner that appears late is worse than a slow one:
test the top of your pages first. Filter: `bepluspb_lazy_html_skip_background`.

**Load Images Early (px)**
Default: 300

Only for "Rewrite the page HTML": an image starts loading this many pixels before it
scrolls into view (0–2000; doubled on slow 2G/3G connections). Images inside a slider
or carousel (Swiper, Slick, Owl, Elementor, Splide, Flickity…) load when the slider
comes into view, so the slides outside its visible frame are not left blank.

**Skip First N Images**
Default: 1

On WordPress 6.4 or newer, controls Core's media omission threshold (0–20). The
default 3 leaves Core's own threshold unchanged. The plugin does not identify or
promise an LCP image.

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
Gutenberg block styles. Automatically skipped on block (full-site-editing)
themes, whose design is delivered through those stylesheets.

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
* **Expires headers** (mod_expires): 1-year cache for images and fonts, 1 month
  for CSS/JS; no-cache for HTML, feeds, plain text and API responses.
* **Cache-Control headers** (mod_headers): `max-age=31536000, public, immutable`
  for images and fonts, and for CSS/JS requested with a version query string
  (`?ver=`, `?v=`, `?version=`); unversioned CSS/JS get 30 days. PHP/HTML get
  `no-cache, must-revalidate` only when the application set no Cache-Control
  of its own.
* Rules are written when you save settings, so re-save the Advanced tab after
  updating to refresh an existing block.

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

**Serve WebP/AVIF Images**
Default: off

When enabled, JPG/PNG image URLs are swapped for a same-named `.avif` or
`.webp` file when one already exists next to it and the visitor's browser
declares support for that format (via the `Accept` header). Prefers AVIF over
WebP when both are supported and both files exist. This plugin does not
generate or convert any images itself — it only rewrites the URL when a
matching file is already present on disk (e.g. created by WordPress core,
a theme build step, or another optimization/conversion tool).

---

= 🔶 Cloudflare =

Keep Cloudflare's edge cache in sync with this plugin's own cache, and
control Cloudflare Development Mode, without leaving wp-admin. This does
not set up Cloudflare as a CDN/DNS proxy for you — it only talks to a
Cloudflare zone you have already added your domain to. All Cloudflare API
calls are admin-triggered (nonce + capability checked, and rate limited),
except the optional "Purge Edited Pages" below, which runs when a post is saved;
nothing is called on a regular front-end page load.

**Enable Cloudflare Integration**
Default: off

When on, the existing "Clear Cache" button also purges Cloudflare's cache
for the configured zone, in addition to this plugin's own CSS/JS cache.

**Purge Edited Pages**
Default: off

When a post or page is published, updated, unpublished or trashed, only its
addresses are purged at Cloudflare: the page itself (and its old address if the
slug changed), the home page, the posts page or post type archive, its category/tag
archives, the author archive and the main feed. Runs on saves in wp-admin, over REST,
from WP-Cron (scheduled posts) and WP-CLI, never on a visitor's page load. A bulk edit
(more than 60 addresses) purges the whole zone instead, at most once a minute. Page 2+
of archives and date archives are not purged (they expire with Cloudflare's cache
lifetime). The last result is shown under the checkbox. Only useful when Cloudflare
caches your HTML ("Cache Everything" rule).

**API Token**
Default: empty

Your Cloudflare API Token (not a Global API Key — legacy Global API Keys
are not supported). Create one at
[dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens)
scoped to Zone > Cache Purge and Zone > Zone Settings. Click **Test
Connection** to validate the token and auto-detect the matching zone for
this site's domain — the zone ID/name are never typed in by hand, only
ever written by a successful Test Connection.

**Matched Zone**
Read-only, auto-populated by Test Connection.

Shows the Cloudflare zone name and ID that was matched to this site's
domain. Displays "Not configured" until Test Connection succeeds.

**Purge Cloudflare Now**

Button in the "Cloudflare Cache" card — immediately purges everything on
the configured zone via the Cloudflare API, independent of the main
Clear Cache button.

**Development Mode (Turn ON / Turn OFF / Check Status)**

Buttons in the "Development Mode" card — temporarily bypasses Cloudflare's
cache so origin changes show up immediately, useful while actively editing
a live site. Cloudflare automatically turns Development Mode off after 3
hours; "Check Status" always re-queries the live value from Cloudflare
rather than trusting a locally cached state.

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

