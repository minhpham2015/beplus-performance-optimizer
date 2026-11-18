/**
 * Browser test of Delay JS (dev-only: lives in docs/, never shipped).
 *
 *  1. SIMPLE mode (assets/js/delay.js): a delayed script that registers DOMContentLoaded / load listeners (as Contact
 *     Form 7 does) must still get them called once, in order, and a form submitted before the scripts ran must be
 *     handled by the form's own JS, not sent as a page-reload POST.
 *  3. ADVANCED mode, Elementor entrance animations: a first-screen ".elementor-invisible" element is revealed (and its
 *     animation class added) on load, without waiting for the first interaction; one far below the fold is left alone.
 *
 *  2. ADVANCED mode (PHP rewriter + runtime): the built-in never-delay rules (Elementor's lazy-background script,
 *     "no-js" togglers, consent banners, data-no-optimize / data-cfasync="false") run at once, everything else waits
 *     for the first interaction and then runs.
 *
 * Needs Playwright with Chromium:  npm i playwright && npx playwright install chromium
 * Run:                             node docs/delay-js-browser-test.js
 */
const { execFileSync } = require('child_process'), http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { chromium } = require('playwright');

const root = path.join(__dirname, '..');
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'bepluspb-delay-'));
const phpFile = path.join(work, 'render.php');
fs.writeFileSync(phpFile, `<?php
define('ABSPATH', 1); define('BEPLUSPB_PLUGIN_DIR', $argv[1] . '/'); define('BEPLUSPB_VERSION', 't');
function apply_filters($t, $v) { return $v; } define('ELEMENTOR_VERSION', '3.30.0');
function wp_json_encode($v) { return json_encode($v); } function wp_generate_password($n = 12, $s = true) { return substr(bin2hex(random_bytes(16)), 0, $n); }
function bepluspb_get_options() { return array('js_exclude' => '', 'js_delay_rdelay' => 0); } function bepluspb_parse_exclude_list($t) { return array(); }
require $argv[1] . '/includes/class-bepluspb-utils.php'; require $argv[1] . '/includes/class-bepluspb-js.php';
$m = new ReflectionMethod('BEPLUSPB_JS', 'rewrite_scripts'); $m->setAccessible(true);
echo $m->invoke(null, file_get_contents($argv[2]));
`);

// ---- fixtures -----------------------------------------------------------------------------------------------------
const lateScript = `
window.__log = window.__log || [];
document.addEventListener('DOMContentLoaded', function (e) { window.__log.push('DCL:' + (e.target === document)); document.querySelector('form').addEventListener('submit', function (ev) { ev.preventDefault(); window.__ajax = (window.__ajax || 0) + 1; }); });
window.addEventListener('load', function (e) { window.__log.push('load:' + (e.target === window || e.target === document)); });
window.__ranLate = true;`;
const simplePage = `<!doctype html><html><head><meta charset="utf-8"><title>simple</title></head><body>
<form action="/native-post" method="post"><input name="n" value="x"><input type="submit" value="Send"></form>
<script type="text/plain" data-bepluspb-delay="1" data-bepluspb-src="/s/late.js"></script>
<script>var bepluspbDelayConfig = {"skip": []};</script>
<script src="/s/delay.js"></script>
</body></html>`;
const advancedSource = `<!doctype html><html><head><meta charset="utf-8"><title>adv</title></head><body>
<p>content</p>
<h2 id="hero" class="elementor-invisible" data-settings='{"_animation":"fadeInUp","_animation_delay":50}'>Hero</h2>
<div style="height:3000px"></div>
<h2 id="far" class="elementor-invisible" data-settings='{"_animation":"fadeIn"}'>Far</h2>
<script>const lazyloadRunObserver = () => {}; window.__elementorLazy = true;</script>
<script>document.body.classList.remove("no-js"); window.__noJs = true;</script>
<script src="/s/cookie-notice/js/front.min.js"></script>
<script data-no-optimize="1">window.__optOut = true;</script>
<script src="/s/optout.js" data-cfasync="false"></script>
<script>window.__normalInline = true;</script>
<script src="/s/normal.js"></script>
</body></html>`;
const advancedFile = path.join(work, 'adv-src.html');
fs.writeFileSync(advancedFile, advancedSource);
const advancedPage = execFileSync('php', [phpFile, root, advancedFile], { encoding: 'utf8' });

let natives = 0;
const server = http.createServer((req, res) => {
  const u = req.url.split('?')[0];
  const js = (body) => { res.setHeader('content-type', 'application/javascript'); res.end(body); };
  if (u === '/simple') { res.setHeader('content-type', 'text/html'); return res.end(simplePage); }
  if (u === '/advanced') { res.setHeader('content-type', 'text/html'); return res.end(advancedPage); }
  if (u === '/s/delay.js') { return js(fs.readFileSync(path.join(root, 'assets/js/delay.js'))); }
  if (u === '/s/late.js') { return js(lateScript); }
  if (u === '/s/cookie-notice/js/front.min.js') { return js('window.__cookie = true;'); }
  if (u === '/s/optout.js') { return js('window.__optOutSrc = true;'); }
  if (u === '/s/normal.js') { return js('window.__normalSrc = true;'); }
  if (u === '/native-post') { natives++; res.setHeader('content-type', 'text/html'); return res.end('NATIVE POST'); }
  res.statusCode = 404; res.end('');
});

let failed = 0;
const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) { failed++; } };

(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch();
  const quiet = () => { // headless Chromium sends a real mouseover at load: it would count as the first interaction.
    ['mouseover', 'mousemove', 'mouseout', 'mouseenter', 'mouseleave'].forEach(n => window.addEventListener(n, e => e.stopImmediatePropagation(), true));
  };

  // ---- 1. Simple mode: late events ------------------------------------------------------------------------------
  let ctx = await browser.newContext(); let p = await ctx.newPage(); await p.addInitScript(quiet);
  await p.goto(base + '/simple', { waitUntil: 'load' }); await p.waitForTimeout(500);
  ok(!(await p.evaluate(() => window.__ranLate)), 'simple: the delayed script has not run before an interaction');
  await p.keyboard.press('Tab'); await p.waitForTimeout(800);
  let r = await p.evaluate(() => ({ ran: window.__ranLate, log: window.__log || [], ownAdd: Object.prototype.hasOwnProperty.call(document, 'addEventListener') || Object.prototype.hasOwnProperty.call(window, 'addEventListener') }));
  ok(r.ran === true, 'simple: the delayed script runs after the first interaction');
  ok(JSON.stringify(r.log) === JSON.stringify(['DCL:true', 'load:true']), 'simple: its DOMContentLoaded then load listeners were each called once, in order (' + r.log.join(',') + ')');
  ok(r.ownAdd === false, 'simple: addEventListener is back to the browser\'s own afterwards');
  await ctx.close();

  // a form submitted before the scripts ran
  natives = 0;
  ctx = await browser.newContext(); p = await ctx.newPage(); await p.addInitScript(quiet);
  await p.goto(base + '/simple', { waitUntil: 'load' }); await p.waitForTimeout(400);
  await p.click('input[type=submit]'); await p.waitForTimeout(1500);
  r = await p.evaluate(() => ({ ajax: window.__ajax || 0, url: location.pathname }));
  ok(r.ajax === 1 && natives === 0 && r.url === '/simple', 'simple: a submit before the scripts ran is handled by the form\'s own JS (AJAX ' + r.ajax + ', native POST ' + natives + ')');
  await ctx.close();

  // ---- 2. Advanced mode: built-in exclusions --------------------------------------------------------------------------
  ctx = await browser.newContext(); p = await ctx.newPage(); await p.addInitScript(quiet);
  await p.goto(base + '/advanced', { waitUntil: 'load' }); await p.waitForTimeout(600);
  r = await p.evaluate(() => ({ lazy: !!window.__elementorLazy, noJs: !!window.__noJs, cookie: !!window.__cookie, optOut: !!window.__optOut, optOutSrc: !!window.__optOutSrc, normalInline: !!window.__normalInline, normalSrc: !!window.__normalSrc }));
  ok(r.lazy, 'advanced: Elementor\'s lazy-background script runs at once');
  ok(r.noJs, 'advanced: a "no-js" class toggler runs at once');
  ok(r.cookie, 'advanced: a cookie-banner script (by URL) runs at once');
  ok(r.optOut && r.optOutSrc, 'advanced: data-no-optimize / data-cfasync="false" scripts run at once');
  ok(!r.normalInline && !r.normalSrc, 'advanced: ordinary scripts still wait for the first interaction');
  await p.keyboard.press('Tab'); await p.waitForTimeout(1200);
  r = await p.evaluate(() => ({ normalInline: !!window.__normalInline, normalSrc: !!window.__normalSrc }));
  ok(r.normalInline && r.normalSrc, 'advanced: ordinary scripts run after the first interaction');
  await ctx.close();

  // ---- 3. Advanced mode: Elementor entrance animations ------------------------------------------------------------
  ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } }); p = await ctx.newPage(); await p.addInitScript(quiet);
  await p.goto(base + '/advanced', { waitUntil: 'load' }); await p.waitForTimeout(600);
  r = await p.evaluate(() => ({ hero: document.getElementById('hero').className, far: document.getElementById('far').className, heroSettings: document.getElementById('hero').getAttribute('data-settings'), inter: !!window.__normalSrc }));
  ok(!/elementor-invisible/.test(r.hero) && /\banimated\b/.test(r.hero) && /\bfadeInUp\b/.test(r.hero), 'elementor: the first-screen element is revealed and animated on load (' + r.hero + ')');
  ok(!/"_animation"\s*:/.test(r.heroSettings), 'elementor: its animation settings are removed so Elementor will not run it twice');
  ok(/elementor-invisible/.test(r.far), 'elementor: an element far below the first screen is left for Elementor');
  ok(!r.inter, 'elementor: ordinary scripts are still delayed');
  await ctx.close();

  await browser.close(); server.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); server.close(); process.exit(1); });
