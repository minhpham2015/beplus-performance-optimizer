/**
 * Browser test of Google Fonts self-hosting with the REAL fonts (dev-only: lives in docs/, never shipped;
 * needs network access to fonts.googleapis.com / fonts.gstatic.com for the download step).
 *
 *  1. runs docs/google-fonts-live-test.php: the plugin's real downloader fetches four stylesheets from Google;
 *  2. the same script renders a sample <head> through BEPLUSPB_Google_Fonts::process() in self-host mode;
 *  3. Chromium loads that page from a local server which only serves the downloaded files, with EVERY request
 *     to another host blocked and recorded: the page must render its fonts without touching Google.
 *
 * Needs Playwright with Chromium:  npm i playwright && npx playwright install chromium
 * Run:                             node docs/google-fonts-browser-test.js
 */
const { execFileSync } = require('child_process'), http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { chromium } = require('playwright');

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'bepluspb-gf-browser-'));
const pageFile = path.join(work, 'head.html');
const out = execFileSync('php', [path.join(__dirname, 'google-fonts-live-test.php'), work, pageFile], { encoding: 'utf8', timeout: 120000 });
console.log(out.split('\n').filter(l => /^(ok|FAIL|stored|\d+ requests|ALL OK|sample)/.test(l)).join('\n'));
if (!/ALL OK/.test(out)) { console.error('download step failed'); process.exit(1); }

const head = fs.readFileSync(pageFile, 'utf8');
const fontsDir = path.join(work, 'uploads', 'bepluspb-cache', 'fonts');
const URL_PREFIX = '/wp-content/uploads/bepluspb-cache/fonts/';
const html = `<!doctype html><html><head>${head}<style>
  body{font-family:'Inter',sans-serif;font-size:20px}
  .he{font-family:'Heebo',sans-serif;font-weight:700}
  .en400{font-family:'Roboto';font-weight:400}.en700{font-family:'Roboto';font-weight:700}
  .icon{font-family:'Material Icons'}
</style></head><body>
<p class="he">שלום עולם</p><p class="inter">Hello world</p><p class="en400">Roboto regular</p><p class="en700">Roboto bold</p><span class="material-icons icon">home</span>
</body></html>`;

const served = [];
const server = http.createServer((req, res) => {
  const u = req.url.split('?')[0];
  if (u === '/') { res.setHeader('content-type', 'text/html'); return res.end(html); }
  if (u.startsWith(URL_PREFIX)) {
    const f = path.join(fontsDir, path.basename(u));
    if (/^[a-f0-9]{16,20}\.(css|woff2)$/.test(path.basename(u)) && fs.existsSync(f)) {
      served.push(path.basename(u));
      res.setHeader('content-type', u.endsWith('.css') ? 'text/css' : 'font/woff2');
      return res.end(fs.readFileSync(f));
    }
  }
  res.statusCode = 404; res.end('nf');
});

let failures = 0; const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) failures++; };
(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1000, height: 700 } });
  const external = [];
  await ctx.route('**/*', route => { const u = new URL(route.request().url()); if (u.origin === base) return route.continue(); external.push(u.href); return route.abort(); });
  const page = await ctx.newPage(); const errors = []; page.on('pageerror', e => errors.push(String(e)));
  await page.goto(base + '/', { waitUntil: 'load' });

  ok(!/googleapis|gstatic/.test(head), 'the page head no longer mentions Google (links and @import all localized)');
  ok(!/preconnect/.test(head), 'and has no preconnect to Google');
  ok((head.match(/bepluspb-cache\/fonts\/[a-f0-9]{16}\.css\?ver=\d+/g) || []).length === 3, 'three local stylesheets are linked (the @import and two links; the Material Symbols one is not on this page)');

  const loaded = await page.evaluate(async () => {
    await Promise.all([
      document.fonts.load('700 20px Heebo', 'שלום'), document.fonts.load('400 20px Inter', 'Hello'),
      document.fonts.load('400 20px Roboto', 'Roboto'), document.fonts.load('700 20px Roboto', 'Roboto'), document.fonts.load('20px "Material Icons"', 'home')
    ]);
    await document.fonts.ready;
    const faces = [...document.fonts].map(f => ({ family: f.family.replace(/['"]/g, ''), weight: f.weight, status: f.status }));
    const used = (sel, fam) => getComputedStyle(document.querySelector(sel)).fontFamily.toLowerCase().includes(fam);
    return { faces, loadedFamilies: [...new Set(faces.filter(f => f.status === 'loaded').map(f => f.family))].sort(), total: faces.length, rules: [...document.styleSheets].reduce((n, s) => { try { return n + s.cssRules.length; } catch (e) { return n; } }, 0) };
  });
  console.log('loaded families:', loaded.loadedFamilies.join(', '), '| @font-face rules:', loaded.total);
  for (const fam of ['Heebo', 'Inter', 'Roboto', 'Material Icons']) { ok(loaded.loadedFamilies.includes(fam), `${fam} was loaded from the local copy`); }
  ok(loaded.faces.some(f => f.family === 'Heebo' && f.weight === '700' && f.status === 'loaded'), 'Heebo 700 (Hebrew text) loaded');
  ok(loaded.faces.some(f => f.family === 'Roboto' && f.weight === '400' && f.status === 'loaded') && loaded.faces.some(f => f.family === 'Roboto' && f.weight === '700' && f.status === 'loaded'), 'both Roboto weights loaded');
  ok(external.length === 0, 'NO request left this site (every other host was blocked): ' + (external.join(', ') || 'none'));
  const woffs = served.filter(f => f.endsWith('.woff2')); const cssFiles = served.filter(f => f.endsWith('.css'));
  const totalWoff = fs.readdirSync(fontsDir).filter(f => f.endsWith('.woff2')).length;
  ok(cssFiles.length === 3 && woffs.length > 0, `served from this site: ${cssFiles.length} stylesheets, ${woffs.length} font files`);
  ok(new Set(woffs).size < totalWoff, `the browser fetched only the subsets the text needs (${new Set(woffs).size} of ${totalWoff} downloaded font files): unicode-range is intact`);
  const icon = await page.evaluate(() => { const s = document.querySelector('.material-icons'); const w = s.getBoundingClientRect().width; return { w, text: s.textContent }; });
  ok(icon.w > 0 && icon.w < 60, 'the icon ligature rendered as a glyph, not as the word "home" (' + Math.round(icon.w) + 'px wide)');
  ok(errors.length === 0, 'no page errors: ' + errors.join(' | '));
  await browser.close(); server.close();
  fs.rmSync(work, { recursive: true, force: true });
  console.log(failures ? failures + ' FAILURE(S)' : 'ALL OK');
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
