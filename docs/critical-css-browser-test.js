/**
 * Browser test for assets/js/critical-css-generator.js (dev-only: lives in docs/, never shipped).
 *
 * Runs the REAL script in headless Chromium against a fake WordPress page (admin bar item,
 * `bepluspbCcss` config, admin-ajax.php) with a stylesheet that exercises: first-screen vs
 * below-the-fold rules, @media (mobile- and desktop-only), @supports, @layer, native nesting,
 * @font-face / @keyframes usage, relative url() rewriting, an inline <style>, a cross-origin
 * stylesheet (unreadable), print-only CSS, admin-bar / logged-in rules and pseudo selectors.
 * It then renders a page that has ONLY the generated CSS and compares the computed layout
 * of the first screen with the page that has the full stylesheets.
 *
 * Needs Playwright with Chromium:  npm i playwright && npx playwright install chromium
 * Run:                             node docs/critical-css-browser-test.js
 *
 * Test-only trick: the panel lives in a CLOSED shadow root; the harness forces it open
 * (addInitScript) so it can read and click it.
 */
const http = require('http'), fs = require('fs'), path = require('path');
const { chromium } = require('playwright');
const JS = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'critical-css-generator.js'), 'utf8');

const SITE_CSS = `
@charset "utf-8";
@layer base, components;
html, body { margin: 0; font-family: "Inter", sans-serif; --stack: "Inter", sans-serif; }
@font-face { font-family: "Inter"; src: url(../fonts/inter.woff2) format("woff2"); }
@font-face { font-family: "Unused Face"; src: url(../fonts/unused.woff2) format("woff2"); }
@keyframes fade { from { opacity: 0 } to { opacity: 1 } }
@keyframes spin-unused { to { transform: rotate(360deg) } }
.hero { height: 500px; background: url(../img/bg.png) center/cover; animation: fade 1s; position: relative; }
.hero::before { content: ""; position: absolute; inset: 0; }
.hero, .never-on-this-page { border: 0; }
.nav a { color: #111; }
.menu-toggle { display: none; }
.nav a:hover { color: red; }
.nav a:focus-visible { outline: 2px solid blue; }
.footer { background: #000; height: 100px; }
.unused-thing { color: red; }
.is-open .menu { display: block; }
@media (min-width: 768px) { .hero { height: 700px; } .desktop-bar { display: flex; height: 40px; } }
@media (max-width: 767px) { .menu-toggle { display: block; height: 44px; } .desktop-bar { display: none; } }
@media print { .hero { display: none; } }
@supports (display: grid) { .grid { display: grid; gap: 4px; } }
@supports (display: nonexistent-value) { .grid { display: nonexistent-value; } }
@layer base { .layered { color: blue; } .layered-below { color: green; } }
.card { color: #222; & .title { font-weight: 700; } }
.admin-bar .hero { top: 32px; }
#wpadminbar * { box-sizing: content-box; }
.logged-in .nav { margin: 1px; }
.footer-below-widget { margin: 0; }
`;
const OTHER_ORIGIN_CSS = `.cross-origin-rule { color: red; }`;
const PRINT_CSS = `.print-rule { display: none; }`;

const cfg = (extra) => ({ ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n0nce', key: 'front', label: 'Front page', storedBytes: 0, enabled: true, inlineAll: false, maxBytes: 150000, settingsUrl: '/wp-admin/options-general.php?page=beplus-performance-booster',
  i18n: { title: 'Critical CSS Generator', working: 'Analysing', mobile: 'Mobile', desktop: 'Desktop', failed: 'Could not analyse', rules: 'rules', skipped: 'stylesheets skipped', total: 'Critical CSS', target: 'Saves for', stored: 'Replaces the saved entry', big: 'This is large', tooBig: 'over the size limit', needEnable: 'The Critical CSS switch is off', inlineAll: 'not used while Inline All CSS', save: 'Save Critical CSS', saving: 'Saving', saved: 'Saved. Reload', error: 'Could not save:', preview: 'Show the CSS', again: 'Analyse again', settings: 'Open settings', close: 'Close', optimizedNote: 'Generated from the original stylesheets',
    nothing: 'Nothing could be analysed. The technical details below say why.',
    failNotLoaded: 'The page did not finish loading in the measuring frame within %d s', failSlow: 'The page started loading in the measuring frame but had not finished after %d s',
    failUnreadable: 'The measuring frame shows a page this tool cannot read', failNoHello: 'The page loaded in the measuring frame but the generator did not run there',
    failError: 'The analysis failed inside the page:', failAnalysisSlow: 'The page loaded but the analysis did not finish within %d s',
    details: 'Technical details (copy them if you ask for help)', copy: 'Copy', copied: 'Copied',
    diagFramingRefused: 'Your site tells browsers not to show its pages in a frame', diagRedirect: 'Opening this page address answers with a redirect',
    here: 'Analyse this window only (no frames)', recovered: 'The script inside the measuring frame did not report (or the page had not finished loading), so this window analysed the frame itself', hereDone: 'Analysed this window only (%s size).' }, ...extra });
const adminBar = '<div id="wpadminbar" style="position:fixed;top:0;left:0;right:0;height:32px;z-index:99999;background:#222"><ul><li id="wp-admin-bar-bepluspb-ccss"><a href="#bepluspb-ccss" style="color:#fff">Generate</a></li></ul></div>';
function body() {
  return `${adminBar}
<header class="nav" style="height:60px"><a href="/" style="display:inline-block;padding:10px">Home</a><button class="menu-toggle">Menu</button><div class="desktop-bar">bar</div></header>
<section class="hero"><h1 class="card"><span class="title">Hero title</span></h1><div class="grid"><i>1</i><i>2</i></div><p class="layered">layered</p><p class="inline-rule">inline</p><div class="hidden-menu"><span class="menu">x</span></div></section>
<div style="height:2500px"></div><p class="layered-below">below</p><footer class="footer">footer</footer><p class="footer-below-widget">w</p>`;
}
const INLINE_CSS = '.inline-rule{padding:4px;color:#333}.hidden-menu{display:none}.inline-below{margin:0}';
const FIXTURES = (otherOrigin) => ({
  '/page': `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>t</title>
<link rel="stylesheet" id="site-css" href="/css/site.css"><link rel="stylesheet" media="print" href="/css/print.css"><link rel="stylesheet" id="admin-bar-css" href="/css/adminbar.css">
<link rel="stylesheet" id="other-css" href="${otherOrigin}/css/other.css"><style id="inline-css">${INLINE_CSS}</style></head><body class="admin-bar logged-in">${body()}
<script>window.bepluspbCcss=${JSON.stringify(cfg({}))};</script><script src="/gen.js"></script></body></html>`,
  '/disabled': null,
});

// Pages that fail in one specific way when they are loaded inside the measuring frame (timing shortened for the test).
const VARIANTS = {
  '/xfo':    { headers: { 'X-Frame-Options': 'DENY' } },
  '/redir':  { redirectFrames: true },
  '/stall':  { stallFrames: true },
  '/slow':   { headScript: "if(window.name.indexOf('bepluspb-ccss-probe:')===0){document.addEventListener('DOMContentLoaded',function(){var i=new Image();i.src='/hang.png?'+Date.now();document.body.appendChild(i);});}" },
  '/err':    { headScript: "if(window.name.indexOf('bepluspb-ccss-probe:')===0){Object.defineProperty(document,'styleSheets',{get:function(){throw new Error('boom from the page');}});}" },
  '/noname': { headScript: "window.name='';" },
  '/rtl':    { rtl: true },
  // the HTML parser of the FRAME is stuck behind a script that never arrives, at the end of the body / in the head
  '/stuck':      { beforeCfgScript: "<script>if(window.name.indexOf('bepluspb-ccss-probe:')===0){document.write('<script src=\"/hang.js\"><\\/script>');}</script>" },
  '/stuck-head': { headScript: "if(window.name.indexOf('bepluspb-ccss-probe:')===0){document.write('<script src=\"/hang.js\"><\\/script>');}" },
  '/pendingcss': { headScript: "if(window.name.indexOf('bepluspb-ccss-probe:')===0){document.write('<link rel=\"stylesheet\" href=\"/hang.css\">');}" },
};
function variantHtml(base, v) {
  let h = base.replace('"maxBytes":150000', '"maxBytes":150000,"timing":{"hello":2500,"result":4000}');
  if (v.rtl) h = h.replace('<html>', '<html dir="rtl" lang="he-IL">');
  if (v.headScript) h = h.replace('<title>t</title>', '<title>t</title><script>' + v.headScript + '</script>');
  if (v.beforeCfgScript) h = h.replace('<script>window.bepluspbCcss=', v.beforeCfgScript + '<script>window.bepluspbCcss=');
  return h;
}
const posts = [];
function makeServer(label) {
  return http.createServer((req, res) => {
    const u = req.url.split('?')[0];
    const send = (type, data, headers = {}) => { res.writeHead(200, { 'content-type': type, ...headers }); res.end(data); };
    if (u === '/gen.js') return send('text/javascript', JS);
    if (u === '/css/site.css') return send('text/css', SITE_CSS);
    if (u === '/css/print.css') return send('text/css', PRINT_CSS);
    if (u === '/css/adminbar.css') return send('text/css', '#wpadminbar{background:#23282d}.admin-bar-only-rule{color:red}');
    if (u === '/css/other.css') return send('text/css', OTHER_ORIGIN_CSS);
    if (u === '/wp-admin/admin-ajax.php' && req.method === 'POST') { let b = ''; req.on('data', d => b += d); req.on('end', () => { posts.push(b); send('application/json', JSON.stringify({ success: true, data: { message: 'Critical CSS saved.' } })); }); return; }
    if (u === '/hang.png' || u === '/hang.js' || u === '/hang.css') return; // never answered
    if (VARIANTS[u]) {
      const v = VARIANTS[u]; const frame = req.headers['sec-fetch-dest'] === 'iframe';
      if (frame && v.stallFrames) return; // the frame request hangs for ever
      if (frame && v.redirectFrames) { res.writeHead(302, { Location: server.otherOrigin + '/landing' }); return res.end(); }
      return send('text/html', variantHtml(server.fixtures['/page'], v), v.headers || {});
    }
    if (u === '/page') return send('text/html', server.fixtures['/page']);
    if (u === '/disabled') return send('text/html', server.fixtures['/page'].replace('"enabled":true', '"enabled":false').replace('"nonBlocking":true', '"nonBlocking":false').replace('"inlineAll":false', '"inlineAll":true').replace('"storedBytes":0', '"storedBytes":1234'));
    if (u === '/only-critical') { const css = server.criticalCss || ''; return send('text/html', `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body class="admin-bar logged-in">${body().replace(/<div id="wpadminbar"[\s\S]*?<\/div>/, '')}</body></html>`); }
    if (u === '/full') return send('text/html', `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/css/site.css"><style>${INLINE_CSS}</style></head><body>${body().replace(/<div id="wpadminbar"[\s\S]*?<\/div>/, '')}</body></html>`);
    res.writeHead(404); res.end('nf');
  });
}
let server;

let failures = 0; const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) failures++; };
async function panelText(pg) { return pg.evaluate(() => { const h = document.querySelector('#bepluspb-ccss-panel'); const c = h && h.shadowRoot && h.shadowRoot.querySelector('.card'); return c ? c.textContent : null; }); }
async function waitDone(pg, ms = 40000) { const t0 = Date.now(); for (;;) { const t = await panelText(pg); if (t && !t.includes('Analysing') && (t.includes('Save Critical CSS') || t.includes('Nothing could be analysed'))) return t; if (Date.now() - t0 > ms) return t; await pg.waitForTimeout(250); } }
const lastPost = () => { const b = posts[posts.length - 1] || ''; const f = n => { const m = b.match(new RegExp('name="' + n + '"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--')); return m ? m[1] : null; }; return { key: f('key'), css: f('css'), source: f('source'), nonce: f('nonce'), action: f('action') }; };

(async () => {
  server = makeServer();
  const other = http.createServer((req, res) => { if (req.url.startsWith('/css/')) { res.writeHead(200, { 'content-type': 'text/css' }); return res.end(OTHER_ORIGIN_CSS); } res.writeHead(200, { 'content-type': 'text/html' }); res.end('<!doctype html><title>landing</title>another origin'); }); // no CORS headers on purpose
  await new Promise(r => other.listen(0, '127.0.0.1', r));
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const otherOrigin = 'http://localhost:' + other.address().port; // another origin: "localhost" vs "127.0.0.1"
  server.fixtures = FIXTURES(otherOrigin);
  server.otherOrigin = otherOrigin;
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  async function open(p, viewport) {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1 });
    await ctx.addInitScript(() => { const o = Element.prototype.attachShadow; Element.prototype.attachShadow = function (i) { return o.call(this, { ...i, mode: 'open' }); }; });
    const pg = await ctx.newPage(); const errs = []; pg.on('pageerror', e => errs.push(String(e)));
    await pg.goto(base + p, { waitUntil: 'load' }); await pg.waitForTimeout(500);
    return { pg, ctx, errs };
  }
  const click = pg => pg.click('#wp-admin-bar-bepluspb-ccss > a');
  const clickBtn = (pg, label) => pg.evaluate(l => { const s = document.querySelector('#bepluspb-ccss-panel').shadowRoot; [...s.querySelectorAll('button')].find(b => b.textContent === l).click(); }, label);

  // ---- generate -------------------------------------------------------------------------
  let { pg, ctx, errs } = await open('/page', { width: 1280, height: 800 });
  ok(!(await panelText(pg)), 'no panel before the admin bar item is clicked');
  await click(pg); let t = await waitDone(pg);
  console.log('--- panel:', (t || '').replace(/\s+/g, ' ').slice(0, 400));
  ok(t && t.includes('Mobile') && t.includes('Desktop') && t.includes('Save Critical CSS'), 'both screen sizes analysed, save offered');
  ok(t && /Critical CSS: [\d.]+ (KB|B)/.test(t) && t.includes('Saves for: Front page'), 'summary shows the size and the target page type');
  ok(t && t.includes('1 stylesheets skipped'), 'the cross-origin stylesheet is reported as skipped (' + (t.match(/(\d+) stylesheets skipped/g) || []).join(',') + ')');
  await clickBtn(pg, 'Save Critical CSS'); await pg.waitForTimeout(600);
  const post = lastPost(); const css = post.css || '';
  server.criticalCss = css;
  ok(post.action === 'bepluspb_ccss_save' && post.key === 'front' && post.source === 'generated' && post.nonce === 'n0nce', 'POST carries action, page type key, source=generated and the nonce');
  console.log('--- generated CSS (' + css.length + ' bytes):\n' + css.replace(/\}/g, '}\n').slice(0, 2500));
  const has = s => css.includes(s), hasRe = r => r.test(css);

  ok(has('font-family: "Inter"') || has("font-family: Inter") || hasRe(/body\{|html, body|html,body/) , 'html/body rules kept');
  ok(hasRe(/\.hero\s*\{[^}]*height: 500px/), 'first-screen rule kept (.hero)');
  ok(has('url("/img/bg.png")') && !has('../img'), 'relative url() rewritten to a root-relative path');
  ok(hasRe(/\.hero::before/), 'pseudo-element rule kept');
  ok(hasRe(/\.nav a:hover/) && hasRe(/\.nav a:focus-visible/), 'hover / focus-visible rules of first-screen elements kept');
  ok(!has('.footer') && !has('.layered-below') && !has('.footer-below-widget'), 'rules for elements below the fold left out');
  ok(!has('.unused-thing') && !has('.is-open') && !has('.never-on-this-page'), 'unused rules and unmatched selectors in a list are left out');
  ok(hasRe(/\.hero\s*\{[^}]*border: 0/) && !has('.hero, .never'), 'a selector list is pruned to the part that matches');
  ok(hasRe(/@media \(min-width: 768px\)\s*\{[^}]*\.hero\s*\{[^}]*height: 700px/) && has('.desktop-bar'), 'desktop-only @media rules come from the desktop frame, still wrapped');
  ok(hasRe(/@media \(max-width: 767px\)\s*\{[^}]*\.menu-toggle/), 'mobile-only @media rules come from the mobile frame, still wrapped');
  ok(css.indexOf('height: 500px') < css.indexOf('height: 700px'), 'source order preserved: base rule before its @media override');
  ok(!has('print'), '@media print and print-only stylesheets left out');
  ok(hasRe(/@supports \(display: grid\)\s*\{[^}]*\.grid/) && !has('nonexistent-value'), '@supports kept only when the browser supports the condition');
  ok(hasRe(/@layer base\s*\{[^}]*\.layered\b/) && has('@layer base, components'), '@layer block and the layer order statement kept');
  ok(has('@font-face') && has('/fonts/inter.woff2') && !has('Unused Face'), '@font-face kept for the used family only (url rewritten)');
  ok(has('@keyframes fade') && !has('spin-unused'), '@keyframes kept for the used animation only');
  ok(has('.inline-rule') && !has('.inline-below'), 'inline <style>: first-screen rule kept, below-fold rule left out');
  ok(hasRe(/\.hidden-menu\s*\{[^}]*display: none/), 'the rule that HIDES a first-screen element is kept (without it the element would flash)');
  ok(!has('.menu {') && !has('.is-open') && !has('.hidden-menu .'), 'but rules for the contents of a hidden subtree are not');
  ok(hasRe(/\.card[^}]*\.title|\.title/) && has('font-weight: 700'), 'natively nested rule kept whole');
  ok(!has('admin-bar') && !has('wpadminbar') && !has('logged-in') && !has('admin-bar-only-rule'), 'admin bar and logged-in rules never included');
  ok(!has('cross-origin-rule'), 'unreadable cross-origin stylesheet contributes nothing');
  ok(!has('@charset') && !has('@import'), 'no @charset / @import');
  ok(css.length > 300 && css.length < 4000, 'result is small (' + css.length + ' bytes)');
  ok(errs.length === 0, 'no page errors: ' + errs.join(' | '));
  ok(await pg.evaluate(() => document.querySelectorAll('iframe').length) === 0, 'measuring frames removed afterwards');
  t = await panelText(pg); ok(t && t.includes('Saved. Reload'), 'success message shown');
  await ctx.close();

  // ---- the critical CSS alone draws the first screen like the full CSS -----------------------
  for (const [name, vp] of [['desktop', { width: 1280, height: 800 }], ['mobile', { width: 390, height: 844 }]]) {
    const probe = async p => { const o = await open(p, vp); const r = await o.pg.evaluate(() => { const g = s => document.querySelector(s); const cs = (s, p) => getComputedStyle(g(s))[p]; const rect = s => { const b = g(s).getBoundingClientRect(); return [Math.round(b.top), Math.round(b.height)].join(':'); };
      return { heroH: cs('.hero', 'height'), heroBg: cs('.hero', 'backgroundImage').replace(/https?:\/\/[^/]+/, ''), navH: rect('.nav'), grid: cs('.grid', 'display'), layered: cs('.layered', 'color'), inline: cs('.inline-rule', 'paddingTop'), toggle: cs('.menu-toggle', 'display'), bar: cs('.desktop-bar', 'display'), font: cs('body', 'fontFamily'), anim: cs('.hero', 'animationName'), title: cs('.title', 'fontWeight'), link: cs('.nav a', 'color') }; }); await o.ctx.close(); return r; };
    const full = await probe('/full'); const only = await probe('/only-critical');
    const diffs = Object.keys(full).filter(k => full[k] !== only[k]).map(k => `${k}: full=${full[k]} critical=${only[k]}`);
    ok(diffs.length === 0, name + ': first screen computes the same with only the critical CSS' + (diffs.length ? ' — DIFFERENCES: ' + diffs.join('; ') : ' (' + Object.keys(full).length + ' properties compared)'));
  }

  // ---- warnings when the feature cannot work ----------------------------------------------------
  ({ pg, ctx } = await open('/disabled', { width: 1280, height: 800 }));
  await click(pg); t = await waitDone(pg);
  ok(t && t.includes('The Critical CSS switch is off') && t.includes('not used while Inline All CSS'), 'panel warns when the switch is off, Non-Render-Blocking is off or Inline All CSS is on');
  ok(t && t.includes('Replaces the saved entry'), 'panel says it replaces the saved entry');
  await ctx.close();

  // ---- analyse again, mobile window -------------------------------------------------------------
  ({ pg, ctx } = await open('/page', { width: 390, height: 844 }));
  await click(pg); await waitDone(pg); await clickBtn(pg, 'Analyse again'); t = await waitDone(pg);
  ok(t && t.includes('Save Critical CSS'), 'works from a phone-sized window and can be repeated');
  await clickBtn(pg, 'Save Critical CSS'); await pg.waitForTimeout(500);
  ok(lastPost().css && lastPost().css.includes('@media (min-width: 768px)'), 'the result does not depend on the size of the administrator\'s own window');
  await ctx.close();

  // ---- a right-to-left site (Hebrew): the English panel text must not be scrambled by the page's direction ------------------------
  ({ pg, ctx } = await open('/rtl', { width: 1280, height: 800 }));
  await click(pg); t = await waitDone(pg);
  const bidi = await pg.evaluate(() => { const c = document.querySelector('#bepluspb-ccss-panel').shadowRoot.querySelector('.card'); return [...c.querySelectorAll('p,h2,h3')].map(e => getComputedStyle(e).unicodeBidi); });
  ok(bidi.length > 0 && bidi.every(v => v === 'plaintext'), 'RTL page: every text element of the panel decides its own direction (' + bidi.length + ' checked)');
  if (process.env.SHOT) { await pg.screenshot({ path: process.env.SHOT }); }
  await ctx.close();

  // ---- failures say WHY (each page below fails in exactly one way; the limits are shortened by cfg.timing) -----------------------
  const detailsOf = pg => pg.evaluate(() => { const h = document.querySelector('#bepluspb-ccss-panel'); const a = h && h.shadowRoot && h.shadowRoot.querySelector('textarea[data-role="details"]'); return a ? a.value : null; });
  async function failing(path) {
    const o = await open(path, { width: 1280, height: 800 });
    await click(o.pg); const text = await waitDone(o.pg, 60000);
    await o.pg.waitForTimeout(1500); // the extra check of the page address finishes after the panel first shows
    return { ...o, text, details: await detailsOf(o.pg) };
  }
  let f = await failing('/xfo');
  ok(f.text && f.text.includes('Nothing could be analysed'), 'framing refused: nothing analysed, and the panel says so');
  ok(f.details && /X-Frame-Options: DENY/.test(f.details), 'framing refused: the technical details show the X-Frame-Options: DENY header');
  ok(f.text.includes('tells browsers not to show its pages in a frame'), 'framing refused: the panel names the cause');
  ok(/mobile: FAILED/.test(f.details) && /desktop: FAILED/.test(f.details) && /browser: /.test(f.details), 'the details cover both screen sizes and the browser');
  // fallback without frames
  await clickBtn(f.pg, 'Analyse this window only (no frames)'); t = await panelText(f.pg);
  ok(t && t.includes('Analysed this window only (Desktop size).') && t.includes('Save Critical CSS'), 'window-only analysis works where frames cannot, and says which size it covered');
  await clickBtn(f.pg, 'Save Critical CSS'); await f.pg.waitForTimeout(500);
  ok(lastPost().css && lastPost().css.includes('height: 500px') && lastPost().css.includes('@media (min-width: 768px)') && !lastPost().css.includes('admin-bar'), 'the window-only result is a real critical CSS (desktop rules, no admin bar) and can be saved');
  await f.ctx.close();

  f = await failing('/redir');
  ok(f.text.includes('cannot read') && /frame readable: NO/.test(f.details), 'redirect to another domain: the frame is reported as unreadable (another origin)');
  ok(/GET of this page: HTTP 200/.test(f.details), 'redirect only for frames: the extra check shows a normal request is answered normally');
  await f.ctx.close();

  f = await failing('/stall');
  ok(f.text.includes('did not finish loading') && /frame load event: never/.test(f.details) && /script in the frame reported in: never/.test(f.details), 'a frame that never loads: "did not finish loading", load event and script both "never"');
  await f.ctx.close();

  // a page that never finishes loading (a hung image) but whose CSS and content are ready: analysed from this window
  f = await failing('/slow');
  ok(f.text.includes('Save Critical CSS') && f.text.includes('analysed the frame itself'), 'a page that never finishes loading: the script reported in, but the page is analysed from this window and the result is usable');
  ok(!f.text.includes('Nothing could be analysed'), 'and it is not reported as a failure');
  await f.ctx.close();

  f = await failing('/err');
  ok(f.text.includes('The analysis failed inside the page: boom from the page') && /error: boom from the page/.test(f.details), 'an exception inside the page is shown with its message, NOT as a timeout');
  ok(!f.text.includes('did not answer in time'), 'and no longer described as "did not answer in time"');
  await f.ctx.close();

  // window.name reset by the page: the script in the frame never runs, but the page loaded completely: recovered
  f = await failing('/noname');
  ok(f.text.includes('Save Critical CSS') && f.text.includes('analysed the frame itself'), 'window.name reset by the page: the script stays silent, the frame is analysed from here (no 25 s wait for nothing)');
  await clickBtn(f.pg, 'Save Critical CSS'); await f.pg.waitForTimeout(500);
  const rec = lastPost().css || '';
  ok(rec.includes('height: 500px') && rec.includes('@media (min-width: 768px)') && rec.includes('@media (max-width: 767px)') && !rec.includes('wpadminbar'), 'the recovered result is the same kind of critical CSS (both sizes, no admin bar)');
  await f.ctx.close();

  // the parser of the frame is stuck behind a script at the END of the body: content and CSS are ready -> recovered
  f = await failing('/stuck');
  ok(f.text.includes('Save Critical CSS') && f.text.includes('analysed the frame itself'), 'parser stuck at the end of the page: the CSS and first screen are ready, so it is analysed from this window');
  await f.ctx.close();

  // stuck in the HEAD: nothing to analyse -> a failure that names the script that blocks the page
  f = await failing('/stuck-head');
  ok(f.text.includes('Nothing could be analysed'), 'parser stuck in the head: nothing can be analysed, and the panel says so');
  ok(/frame document: readyState loading/.test(f.details) && /scripts still loading: [^\n]*\/hang\.js/.test(f.details) && /last script the page parser reached: [^\n]*\/hang\.js/.test(f.details), 'the details name the script the page is stuck on (/hang.js)');
  await f.ctx.close();

  // a stylesheet that never arrives: the CSS is incomplete, so no analysis, and the details name the stylesheet
  f = await failing('/pendingcss');
  ok(f.text.includes('Nothing could be analysed') && /stylesheets still loading: [^\n]*\/hang\.css/.test(f.details), 'a stylesheet that never arrives: no (incomplete) analysis, and the details name /hang.css');
  await f.ctx.close();

  await browser.close(); if (server.closeAllConnections) server.closeAllConnections(); server.close(); other.close();
  console.log(failures ? failures + ' FAILURE(S)' : 'ALL OK');
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
