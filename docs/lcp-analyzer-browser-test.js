/**
 * Browser test for assets/js/lcp-analyzer.js (dev-only: lives in docs/, never shipped).
 *
 * Runs the REAL script in headless Chromium against a fake WordPress page (admin bar item,
 * `bepluspbLcp` config, admin-ajax.php) served from a local port, and checks: image / CSS
 * background / text LCP detection, srcset+sizes capture, the other screen size measured through
 * a pop-up (browsers report LCP only for top-level windows), pop-up blocked / closed early,
 * scope choices, the payload sent to admin-ajax.php and server error handling.
 *
 * Needs Playwright with Chromium:  npm i playwright && npx playwright install chromium
 * Run:                             node docs/lcp-analyzer-browser-test.js
 *
 * Test-only trick: the panel lives in a CLOSED shadow root; the harness forces it open
 * (addInitScript) so it can read and click it. Fixture images are random noise because
 * Chromium ignores low-entropy images when picking the LCP element.
 */
// Harness: real Chromium against a fake WP page that loads the real assets/js/lcp-analyzer.js.
const http = require('http'), zlib = require('zlib'), fs = require('fs');
const { chromium } = require('playwright');
const JS = fs.readFileSync(require('path').join(__dirname, '..', 'assets', 'js', 'lcp-analyzer.js'), 'utf8');

function png(w, h, rgb) {
  const crcT = []; for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; crcT[n] = c >>> 0; }
  const crc = b => { let c = 0xffffffff; for (const x of b) c = crcT[(c ^ x) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (t, d) => { const l = Buffer.alloc(4); l.writeUInt32BE(d.length); const td = Buffer.concat([Buffer.from(t), d]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([l, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  const raw = Buffer.concat(Array.from({ length: h }, () => Buffer.concat([Buffer.from([0]), require('crypto').randomBytes(w * 3)]))); // noise: Chromium ignores low-entropy images for LCP
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const IMG = { '/img/hero-480.png': png(480, 240, [200, 80, 80]), '/img/hero-1024.png': png(1024, 512, [80, 80, 200]), '/img/bg.png': png(1200, 600, [80, 200, 80]) };

const cfg = (extra) => ({ ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n0nce', isHome: true, path: '/', settingsUrl: '/wp-admin/options-general.php?page=beplus-performance-booster', optimized: false, maxEntries: 4, i18n: { kindBg: 'CSS background image', kindImg: 'Image', title: 'LCP Analyzer', mobile: 'Mobile', desktop: 'Desktop', measuring: 'Measuring…', apply: 'Add to Priority Images', noImage: 'There is no image to prioritize.', textLcp: 'The LCP is text', lazy: 'It is lazy-loaded', prioritized: 'Already prioritized', notMeasured: 'Could not measure', popupBlocked: 'The browser blocked the small measuring window', applied: 'Saved. Reload', error: 'Could not save:' }, ...extra });
const adminBar = '<div id="wpadminbar"><ul><li id="wp-admin-bar-bepluspb-lcp"><a href="#bepluspb-lcp">Analyze LCP</a></li></ul></div>';
const page = (body, extra, head = '') => `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>t</title>${head}<style>body{margin:0;font-family:sans-serif}</style></head><body>${adminBar}${body}<script>window.bepluspbLcp=${JSON.stringify(cfg(extra))};</script><script src="/lcp-analyzer.js"></script></body></html>`;

const PAGES = {
  '/img': () => page('<img id="hero" class="wp-post-image" src="/img/hero-1024.png" srcset="/img/hero-480.png 480w, /img/hero-1024.png 1024w" sizes="100vw" style="width:100%;height:auto;display:block" alt="">'),
  '/lazy': () => page('<img id="hero" loading="lazy" src="/img/hero-1024.png" srcset="/img/hero-480.png 480w, /img/hero-1024.png 1024w" sizes="100vw" style="width:100%;height:auto;display:block" alt="">'),
  '/text': () => page('<h1 style="font-size:60px;margin:40px">A very large heading that is the largest paint on this page</h1>'),
  '/bg': () => page('<div id="banner" style="height:500px;background:url(/img/bg.png) center/cover"></div>'),
  '/picture': () => page('<picture><source media="(max-width:600px)" srcset="/img/hero-480.png"><img id="hero" src="/img/hero-1024.png" style="width:100%;height:auto;display:block" alt=""></picture>'),
  '/prio': () => page('<img id="hero" src="/img/hero-1024.png" style="width:100%;height:auto;display:block" alt="">', {}, '<link rel="preload" as="image" href="/img/hero-1024.png" data-bepluspb-priority-image="1" fetchpriority="high">'),
  '/deny': () => page('<img id="hero" src="/img/hero-1024.png" style="width:100%;height:auto;display:block" alt="">'),
  '/inner': () => page('<img id="hero" src="/img/hero-1024.png" style="width:100%;height:auto;display:block" alt="">', { isHome: false, path: '/about/' }),
};
const posts = [];
const server = http.createServer((req, res) => {
  const u = req.url.split('?')[0];
  if (u === '/lcp-analyzer.js') { res.setHeader('content-type', 'text/javascript'); return res.end(JS); }
  if (IMG[u]) { res.setHeader('content-type', 'image/png'); return setTimeout(() => res.end(IMG[u]), 30); }
  if (u === '/wp-admin/admin-ajax.php' && req.method === 'POST') {
    let b = ''; req.on('data', d => b += d); req.on('end', () => { posts.push(b); res.setHeader('content-type', 'application/json'); res.end(JSON.stringify(posts.length % 7 === 0 ? { success: false, data: { message: 'list full' } } : { success: true, data: { message: 'Saved to Priority Images.' } })); });
    return;
  }
  if (PAGES[u]) { res.setHeader('content-type', 'text/html'); if (u === '/deny') res.setHeader('X-Frame-Options', 'DENY'); return res.end(PAGES[u]()); }
  res.statusCode = 404; res.end('nf');
});

let failures = 0; const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) failures++; };
async function panelText(pg) { return pg.evaluate(() => { const h = document.querySelector('#bepluspb-lcp-panel'); const c = h && h.shadowRoot && h.shadowRoot.querySelector('.card'); return c ? c.textContent : null; }); }
async function waitDone(pg, ms = 25000) { const t0 = Date.now(); for (;;) { const t = await panelText(pg); if (t && !t.includes('Measuring…') && t.includes('Mobile') && t.includes('Desktop')) return t; if (Date.now() - t0 > ms) return t; await pg.waitForTimeout(250); } }

(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  async function open(path, viewport) {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1 });
    await ctx.addInitScript(() => { const o = Element.prototype.attachShadow; Element.prototype.attachShadow = function (i) { return o.call(this, { ...i, mode: 'open' }); }; }); // tests only: let us read the panel
    const pg = await ctx.newPage(); const errs = []; pg.on('pageerror', e => errs.push(String(e))); pg.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
    await pg.goto(base + path, { waitUntil: 'load' }); await pg.waitForTimeout(800);
    return { pg, ctx, errs };
  }
  const click = pg => pg.click('#wp-admin-bar-bepluspb-lcp > a');
  const clickApply = pg => pg.evaluate(() => { const s = document.querySelector('#bepluspb-lcp-panel').shadowRoot; [...s.querySelectorAll('button')].find(b => b.textContent === 'Add to Priority Images').click(); });
  const setScope = (pg, v) => pg.evaluate(v => { const s = document.querySelector('#bepluspb-lcp-panel').shadowRoot.querySelector('select'); s.value = v; }, v);
  const lastPost = () => { const b = posts[posts.length - 1] || ''; const m = b.match(/name="entries"\r\n\r\n([\s\S]*?)\r\n--/); return m ? JSON.parse(m[1]) : null; };

  // 1. image LCP, desktop: both screens measured, different candidates -> two entries
  let { pg, ctx, errs } = await open('/img', { width: 1280, height: 800 });
  ok(!(await panelText(pg)), 'no panel before the admin bar item is clicked');
  await click(pg); let t = await waitDone(pg);
  console.log('--- /img desktop panel:', (t || '').replace(/\s+/g, ' ').slice(0, 420));
  ok(t && t.includes('Image') && t.includes('/img/hero-1024.png'), 'desktop: LCP image URL found (root-relative)');
  ok(t && t.includes('/img/hero-480.png'), 'mobile (frame 390px): a different srcset candidate found');
  ok(t && !t.includes('Could not measure'), 'both screen sizes measured');
  await clickApply(pg); await pg.waitForTimeout(600);
  let e = lastPost();
  ok(e && e.length === 2, 'two entries sent (different image per screen): ' + JSON.stringify(e));
  ok(e && e.every(x => x.scope === 'home') && e.some(x => x.device === 'desktop' && x.url === '/img/hero-1024.png') && e.some(x => x.device === 'mobile' && x.url === '/img/hero-480.png'), 'entries: home scope, per-device URLs');
  ok(e && e.every(x => x.srcset.includes('hero-480.png 480w') && x.sizes === '100vw'), 'srcset and sizes captured from the <img>');
  ok(/n0nce/.test(posts[posts.length - 1]) && /bepluspb_lcp_apply/.test(posts[posts.length - 1]), 'nonce and action sent');
  t = await panelText(pg); ok(t && t.includes('Saved'), 'success message shown');
  ok(errs.length === 0, 'no page errors: ' + errs.join(' | '));
  await pg.evaluate(() => document.querySelector('#bepluspb-lcp-panel').shadowRoot.querySelector('button[aria-label]') && 0);
  ok(ctx.pages().length === 1, 'measuring pop-up closed itself afterwards (' + ctx.pages().length + ' page(s) open)');
  await ctx.close();

  // 2. scope select "whole site"
  ({ pg, ctx } = await open('/inner', { width: 1280, height: 800 }));
  await click(pg); await waitDone(pg); await setScope(pg, 'all'); await clickApply(pg); await pg.waitForTimeout(500);
  e = lastPost(); ok(e && e.every(x => x.scope === 'all'), 'whole-site scope sent');
  await click(pg); await waitDone(pg); await clickApply(pg); await pg.waitForTimeout(500);
  e = lastPost(); ok(e && e.every(x => x.scope === '/about/'), 'default scope on an inner page is its path');
  await ctx.close();

  // 3. mobile window: current = mobile
  ({ pg, ctx } = await open('/img', { width: 390, height: 844 }));
  await click(pg); t = await waitDone(pg);
  ok(t && t.indexOf('Mobile') < t.indexOf('Desktop'), 'mobile window lists Mobile first');
  ok(t && t.includes('hero-480.png') && t.includes('hero-1024.png'), 'mobile window: own result + desktop frame result');
  await ctx.close();

  // 4. lazy
  ({ pg, ctx } = await open('/lazy', { width: 1280, height: 800 }));
  await click(pg); t = await waitDone(pg); ok(t && t.includes('It is lazy-loaded'), 'lazy-loaded LCP image is called out');
  await ctx.close();

  // 5. text LCP
  ({ pg, ctx } = await open('/text', { width: 1280, height: 800 }));
  await click(pg); t = await waitDone(pg); ok(t && t.includes('The LCP is text'), 'text LCP explained');
  ok(t && t.includes('There is no image to prioritize.') && !t.includes('Add to Priority Images'), 'no apply button when there is no image');
  await ctx.close();

  // 6. CSS background
  ({ pg, ctx } = await open('/bg', { width: 1280, height: 800 }));
  await click(pg); t = await waitDone(pg); ok(t && t.includes('CSS background image') && t.includes('/img/bg.png'), 'background-image LCP detected with its URL');
  await clickApply(pg); await pg.waitForTimeout(500); e = lastPost();
  ok(e && e.length === 1 && e[0].device === 'all' && e[0].url === '/img/bg.png' && e[0].srcset === '', 'same background on both screens -> one entry for all devices, no srcset');
  await ctx.close();

  // 7. <picture>: no srcset hint (the chosen <source> is not in the img srcset)
  ({ pg, ctx } = await open('/picture', { width: 1280, height: 800 }));
  await click(pg); await waitDone(pg); await clickApply(pg); await pg.waitForTimeout(500); e = lastPost();
  ok(e && e.every(x => x.srcset === ''), '<picture>: no srcset hint is sent');
  ok(e && e.some(x => x.device === 'mobile' && x.url === '/img/hero-480.png'), '<picture>: mobile source URL found through the frame');
  await ctx.close();

  // 8. already prioritized
  ({ pg, ctx } = await open('/prio', { width: 1280, height: 800 }));
  await click(pg); t = await waitDone(pg); ok(t && t.includes('Already prioritized'), 'an image already preloaded by Priority Images is recognised');
  await ctx.close();

  // 9. pop-up blocked: own screen still reported, other says so, only the measured screen is saved
  ({ pg, ctx } = await open('/deny', { width: 1280, height: 800 }));
  await pg.evaluate(() => { window.open = () => null; });
  await click(pg); t = await waitDone(pg, 10000);
  ok(t && t.includes('The browser blocked the small measuring window') && t.includes('/img/hero-1024.png'), 'pop-up blocked: own screen still reported, the other one explains how to unblock');
  await clickApply(pg); await pg.waitForTimeout(500); e = lastPost();
  ok(e && e.length === 1 && e[0].device === 'desktop', 'only the measured screen is saved, as a desktop-only entry');
  await ctx.close();

  // 9b. measuring window closed by the administrator before it reports
  ({ pg, ctx } = await open('/img', { width: 1280, height: 800 }));
  const popupP = ctx.waitForEvent('page'); await click(pg); const pop = await popupP; await pop.close();
  const tc0 = Date.now(); let tc; for (;;) { tc = await panelText(pg); if (tc && tc.includes('Could not measure')) break; if (Date.now() - tc0 > 20000) break; await pg.waitForTimeout(300); }
  ok(tc && tc.includes('Could not measure') && tc.includes('/img/hero-1024.png'), 'pop-up closed early: reported as not measured within ' + Math.round((Date.now() - tc0) / 1000) + ' s, own screen kept');
  await ctx.close();

  // 10. server error is shown and the button re-enabled
  while (posts.length % 7 !== 6) posts.push('filler');
  ({ pg, ctx } = await open('/img', { width: 1280, height: 800 }));
  await click(pg); await waitDone(pg); await clickApply(pg); await pg.waitForTimeout(600); t = await panelText(pg);
  ok(t && t.includes('Could not save:') && t.includes('list full'), 'server error message is shown');
  await ctx.close();

  // 11. the probe frame itself never opens a panel nor posts
  const before = posts.length;
  ({ pg, ctx } = await open('/img', { width: 1280, height: 800 }));
  ok(!(await panelText(pg)) && posts.length === before, 'a plain page view opens nothing and posts nothing');
  await ctx.close();

  await browser.close(); server.close();
  console.log(failures ? failures + ' FAILURE(S)' : 'ALL OK');
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
