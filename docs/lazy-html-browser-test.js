/**
 * Browser test of the HTML lazy-load engine (dev-only: lives in docs/, never shipped).
 *
 * PHP renders a sample page through BEPLUSPB_Lazy_HTML::process(); Chromium loads it from a local server that
 * logs every image request, and checks that: the first images load at once, far images are NOT requested until
 * the visitor scrolls near them, <picture> sources are honoured, images added later by scripts are handled,
 * and with JavaScript off the original images load (the placeholders are hidden).
 *
 * Needs Playwright with Chromium:  npm i playwright && npx playwright install chromium
 * Run:                             node docs/lazy-html-browser-test.js
 */
const { execFileSync } = require('child_process'), http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { chromium } = require('playwright');

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'bepluspb-lazy-browser-'));
const phpFile = path.join(work, 'render.php');
fs.writeFileSync(phpFile, `<?php
define('ABSPATH', 1);
function apply_filters($t, $v) { return $v; } function is_admin() { return false; } function is_feed() { return false; } function wp_doing_ajax() { return false; }
$root = $argv[1];
require $root . '/includes/class-bepluspb-utils.php'; require $root . '/includes/class-bepluspb-css.php'; require $root . '/includes/class-bepluspb-lazy-html.php';
$body = '<img id="top1" src="/img/top1.png" width="100" height="100"><img id="top2" src="/img/top2.png" width="100" height="100">'
  . '<img id="near" src="/img/near.png" width="100" height="100">'
  . '<div style="height:1500px">spacer</div>'
  . '<img id="far" src="/img/far.png" width="100" height="100" srcset="/img/far-1x.png 1x, /img/far-2x.png 2x">'
  . '<div style="height:1500px">spacer</div>'
  . '<picture id="pic"><source type="image/webp" srcset="/img/pic.webp"><img id="picimg" src="/img/pic.png" width="100" height="100"></picture>'
  . '<div style="height:1500px">spacer</div>'
  . '<img id="skipme" src="/img/skipme.png" width="100" height="100" class="skip-lazy">'
  . '<div id="later"></div>';
echo BEPLUSPB_Lazy_HTML::process('<!doctype html><html><head><meta charset="utf-8"><title>t</title></head><body style="margin:0">' . $body . '</body></html>', array('lazy_html_skip_first' => 2));
`);
const html = execFileSync('php', [phpFile, path.join(__dirname, '..')], { encoding: 'utf8' });

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const requests = [];
const server = http.createServer((req, res) => {
  const u = req.url.split('?')[0];
  if (u === '/') { res.setHeader('content-type', 'text/html'); return res.end(html); }
  if (u.startsWith('/img/')) { requests.push(u); res.setHeader('content-type', u.endsWith('.webp') ? 'image/png' : 'image/png'); return res.end(PNG); }
  res.statusCode = 404; res.end('');
});

let failed = 0;
const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) { failed++; } };
const has = n => requests.some(r => r.includes(n));

(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + server.address().port + '/';
  const browser = await chromium.launch();

  // ---- JavaScript on -------------------------------------------------------------------------------
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', e => errors.push(String(e)));
  await page.goto(base, { waitUntil: 'load' }); await page.waitForTimeout(500);
  ok(has('top1.png') && has('top2.png'), 'the first two images are requested at once');
  ok(has('near.png'), 'an image close to the first screen is requested at load (300 px margin)');
  ok(has('skipme.png'), 'an image with the class skip-lazy loads normally');
  ok(!has('far.png') && !has('far-1x') && !has('far-2x') && !has('pic.png') && !has('pic.webp'), 'the far image and the <picture> are NOT requested before scrolling');
  const ph = await page.evaluate(() => document.getElementById('far').getAttribute('src'));
  ok(/^data:image\/svg\+xml/.test(ph), 'a far image shows the SVG placeholder');
  const ratio = await page.evaluate(() => { const r = document.getElementById('far').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
  ok(ratio[0] === 100 && ratio[1] === 100, 'the placeholder reserves the image size (' + ratio.join('x') + ')');

  await page.evaluate(() => window.scrollTo(0, 1100)); await page.waitForTimeout(600);
  ok(has('far-1x.png') || has('far-2x.png') || has('far.png'), 'scrolling near the far image loads it (' + requests.filter(r => r.includes('far')).join(',') + ')');
  const far = await page.evaluate(() => { const i = document.getElementById('far'); return [i.hasAttribute('data-bpb-src'), i.hasAttribute('data-bpb-srcset'), i.hasAttribute('srcset'), i.naturalWidth > 0]; });
  ok(far[0] === false && far[1] === false && far[2] === true && far[3] === true, 'the far image got its attributes back and decoded');
  ok(!has('pic.'), 'the <picture> is still not requested');

  await page.evaluate(() => window.scrollTo(0, 2700)); await page.waitForTimeout(600);
  ok(has('pic.webp') && !has('pic.png'), 'the <picture> loads its webp source (not the fallback img)');
  const pic = await page.evaluate(() => { const s = document.querySelector('#pic source'); return [s.hasAttribute('srcset'), s.hasAttribute('data-bpb-srcset')]; });
  ok(pic[0] === true && pic[1] === false, 'the <source> got its srcset back');

  await page.evaluate(() => {
    const i = document.createElement('img'); i.id = 'dyn'; i.setAttribute('data-bpb-src', '/img/dyn.png'); i.src = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3C/svg%3E"; i.width = 100; i.height = 100;
    document.getElementById('later').appendChild(i);
  });
  await page.evaluate(() => window.scrollTo(0, 4400)); await page.waitForTimeout(800);
  ok(has('dyn.png'), 'an image added later by a script is picked up (MutationObserver)');
  ok(errors.length === 0, 'no page errors' + (errors.length ? ': ' + errors.join('; ') : ''));
  await ctx.close();

  // ---- JavaScript off ----------------------------------------------------------------------------------
  requests.length = 0;
  const ctx2 = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 800 } });
  const p2 = await ctx2.newPage();
  await p2.goto(base, { waitUntil: 'load' }); await p2.waitForTimeout(500);
  ok(has('far-1x.png') && has('pic.png'), 'without JavaScript the <noscript> originals load (' + requests.join(',') + ')');
  const hidden = await p2.evaluate(() => { const i = document.getElementById('far'); return i ? getComputedStyle(i).display : 'missing'; });
  ok(hidden === 'none' || hidden === 'missing', 'without JavaScript the placeholder is hidden (display: ' + hidden + ')');
  await ctx2.close();

  await browser.close(); server.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); server.close(); process.exit(1); });
