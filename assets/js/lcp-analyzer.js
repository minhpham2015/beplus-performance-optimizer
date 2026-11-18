/**
 * LCP Analyzer (administrators only).
 *
 * Admin bar → "Analyze LCP (this page)" measures the Largest Contentful Paint element of the
 * page in the administrator's own browser, for the current screen size and — through a small
 * same-origin pop-up window showing this very page — for the other one (mobile/desktop), then
 * offers to add the image to "Priority Images". (A pop-up, not an iframe: browsers only report
 * LCP for top-level windows.)
 *
 * Nothing is sent anywhere except the admin-ajax.php of this site, and only after the
 * administrator presses "Add to Priority Images". Page data is only ever written with
 * textContent, never as HTML.
 */
(function () {
	'use strict';

	var cfg = window.bepluspbLcp;
	if (!cfg || !document.body) { return; }

	var PROBE_PREFIX = 'bepluspb-lcp-probe:';
	var BREAKPOINT = 768;
	var WINDOWS = { mobile: { w: 390, h: 844 }, desktop: { w: 1366, h: 768 } };
	var PROBE_SETTLE_MS = 1500;
	var PROBE_TIMEOUT_MS = 15000;
	var SLOW_MS = 2500;

	function t(key) { return (cfg.i18n && cfg.i18n[key]) || key; }

	// ---------------------------------------------------------------------------------
	// Measuring
	// ---------------------------------------------------------------------------------

	function lcpSupported() {
		return 'PerformanceObserver' in window &&
			Array.isArray(PerformanceObserver.supportedEntryTypes) &&
			PerformanceObserver.supportedEntryTypes.indexOf('largest-contentful-paint') !== -1;
	}

	/** URL as it should be stored: root-relative on this site, absolute elsewhere, '' for data:/blob:. */
	function storableUrl(raw) {
		if (!raw) { return ''; }
		try {
			var u = new URL(raw, window.location.href);
			if (u.protocol !== 'http:' && u.protocol !== 'https:') { return ''; }
			return u.origin === window.location.origin ? u.pathname + u.search : u.href;
		} catch (e) { return ''; }
	}

	/** Same key the plugin matches images by: lower-case path without -123x456 / -scaled. */
	function matchKey(url) {
		try {
			var path = new URL(url, window.location.href).pathname.toLowerCase();
			path = decodeURIComponent(path);
			return path.replace(/(?:-\d{1,5}x\d{1,5}|-scaled)(\.[a-z0-9]+)$/, '$1');
		} catch (e) { return ''; }
	}

	function label(el) {
		if (!el || !el.tagName) { return ''; }
		var s = el.tagName.toLowerCase();
		if (el.id) { s += '#' + el.id; }
		var cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/)[0] : '';
		if (cls) { s += '.' + cls; }
		return s.length > 80 ? s.slice(0, 77) + '…' : s;
	}

	function prioritizedKeys() {
		var keys = {};
		document.querySelectorAll('link[rel="preload"][data-bepluspb-priority-image]').forEach(function (link) {
			var key = matchKey(link.getAttribute('href') || '');
			if (key) { keys[key] = true; }
		});
		return keys;
	}

	/** Turn an LCP performance entry into the plain object the panel and the AJAX call use. */
	function describe(entry) {
		var el = entry.element;
		var tag = el && el.tagName ? el.tagName.toLowerCase() : '';
		var out = {
			kind: 'text',
			tag: tag,
			label: label(el),
			time: Math.round(entry.renderTime || entry.loadTime || entry.startTime || 0),
			url: '',
			srcset: '',
			sizes: '',
			lazy: false,
			fetchpriority: '',
			picture: false,
			prioritized: false
		};

		if (entry.url || tag === 'img') {
			if (tag === 'img') {
				out.kind = 'img';
				out.url = storableUrl(el.currentSrc || el.src || entry.url);
				out.lazy = el.getAttribute('loading') === 'lazy';
				out.fetchpriority = el.getAttribute('fetchpriority') || '';
				out.picture = !!(el.parentElement && el.parentElement.tagName === 'PICTURE');
				// A <picture> may pick a <source> the img's own srcset does not list.
				if (!out.picture) {
					out.srcset = el.getAttribute('srcset') || '';
					out.sizes = el.getAttribute('sizes') || '';
				}
			} else {
				out.kind = tag === 'video' ? 'video' : (tag === 'image' ? 'other' : 'bg');
				out.url = storableUrl(entry.url);
			}
		}

		if (out.url) {
			out.prioritized = !!prioritizedKeys()[matchKey(out.url)];
		}
		return out;
	}

	/** Latest LCP candidate of this window (entries before now are replayed by `buffered`). */
	function readLcp(callback) {
		if (!lcpSupported()) { callback({ unsupported: true }); return; }
		var last = null;
		var po;
		try {
			po = new PerformanceObserver(function (list) {
				var entries = list.getEntries();
				if (entries.length) { last = entries[entries.length - 1]; }
			});
			po.observe({ type: 'largest-contentful-paint', buffered: true });
		} catch (e) { callback({ unsupported: true }); return; }

		window.setTimeout(function () {
			var rest = po.takeRecords();
			if (rest.length) { last = rest[rest.length - 1]; }
			po.disconnect();
			var result = last ? describe(last) : { none: true };
			result.width = window.innerWidth;
			callback(result);
		}, 150);
	}

	function whenLoaded(fn) {
		if (document.readyState === 'complete') { fn(); } else { window.addEventListener('load', fn, { once: true }); }
	}

	// ---------------------------------------------------------------------------------
	// Measuring window: this same script, running inside the pop-up copy of the page
	// ---------------------------------------------------------------------------------

	function deviceOfWidth(width) { return width >= BREAKPOINT ? 'desktop' : 'mobile'; }

	if (window.name && window.name.indexOf(PROBE_PREFIX) === 0) {
		var token = window.name.slice(PROBE_PREFIX.length);
		whenLoaded(function () {
			window.setTimeout(function () {
				readLcp(function (result) {
					try {
						if (window.opener) { window.opener.postMessage({ bepluspbLcp: token, result: result }, window.location.origin); }
					} catch (e) { /* opener gone */ }
					window.close();
				});
			}, PROBE_SETTLE_MS);
		});
		return;
	}

	/**
	 * Open the measuring pop-up. Must run synchronously inside the click handler, or the
	 * browser blocks it. Returns null when it was blocked.
	 */
	function openProbe(device) {
		var size = WINDOWS[device];
		var probeToken = String(Date.now()) + Math.random().toString(36).slice(2);
		var features = 'popup=yes,width=' + size.w + ',height=' + size.h + ',left=0,top=0';
		var win = null;
		try { win = window.open(window.location.href.split('#')[0], PROBE_PREFIX + probeToken, features); } catch (e) { win = null; }
		return win ? { win: win, token: probeToken, device: device } : null;
	}

	/** Wait for the pop-up's result (or its closing / a timeout) and close it. */
	function waitProbe(probe, callback) {
		if (!probe) { callback({ blocked: true }); return; }
		var finished = false;
		var timer;
		var poll;

		function finish(result) {
			if (finished) { return; }
			finished = true;
			window.removeEventListener('message', onMessage);
			window.clearTimeout(timer);
			window.clearInterval(poll);
			try { if (!probe.win.closed) { probe.win.close(); } } catch (e) { /* already gone */ }
			callback(result);
		}
		function onMessage(event) {
			var data = event.data;
			if (event.origin !== window.location.origin || event.source !== probe.win ||
				!data || data.bepluspbLcp !== probe.token || !data.result) { return; }
			var result = data.result;
			// Some browsers will not open a window of the requested size (or open a tab): then it
			// is not the screen size we wanted and must not be saved as such.
			finish(typeof result.width === 'number' && deviceOfWidth(result.width) !== probe.device ? { failed: true } : result);
		}
		window.addEventListener('message', onMessage);
		timer = window.setTimeout(function () { finish({ failed: true }); }, PROBE_TIMEOUT_MS);
		// Closed by the administrator before it reported.
		poll = window.setInterval(function () { try { if (probe.win.closed) { window.setTimeout(function () { finish({ failed: true }); }, 400); } } catch (e) { finish({ failed: true }); } }, 500);
	}

	// ---------------------------------------------------------------------------------
	// Panel (closed shadow root, so the theme's CSS cannot touch it and ours cannot leak)
	// ---------------------------------------------------------------------------------

	var CSS = [
		':host{all:initial}',
		'p,h2,h3,dt,dd,li,button,a,span,label{unicode-bidi:plaintext}',
		'.card{box-sizing:border-box;position:fixed;inset-block-end:16px;inset-inline-end:16px;z-index:2147483000;width:min(420px,calc(100vw - 32px));max-height:calc(100vh - 32px);overflow:auto;background:#fff;color:#1d2327;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.25);font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:14px 16px}',
		'h2{margin:0 0 8px;font-size:15px;font-weight:600;display:flex;justify-content:space-between;align-items:center}',
		'.note{color:#50575e;font-size:12px;margin:0 0 10px}',
		'section{border-top:1px solid #dcdcde;padding:10px 0}',
		'h3{margin:0 0 4px;font-size:13px;font-weight:600}',
		'dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:2px 10px}',
		'dt{color:#50575e}dd{margin:0;word-break:break-all}',
		'ul{margin:6px 0 0;padding-inline-start:18px}li{margin:2px 0}',
		'.warn{color:#8a4b00}.ok{color:#007017}.err{color:#b32d2e}',
		'button,a.btn{font:inherit;cursor:pointer;border:1px solid #2271b1;background:#2271b1;color:#fff;border-radius:4px;padding:5px 12px;text-decoration:none;display:inline-block}',
		'button.secondary,a.btn.secondary{background:#fff;color:#2271b1}',
		'button[disabled]{opacity:.6;cursor:default}',
		'select{font:inherit;padding:3px 6px;margin-inline-end:6px}',
		'.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}'
	].join('');

	var host = null;
	var root = null;
	var state = { results: {}, pending: null };

	function el(tag, props, children) {
		var node = document.createElement(tag);
		if (props) {
			Object.keys(props).forEach(function (k) {
				if (k === 'text') { node.textContent = props[k]; } else if (k === 'class') { node.className = props[k]; } else { node.setAttribute(k, props[k]); }
			});
		}
		(children || []).forEach(function (c) { if (c) { node.appendChild(c); } });
		return node;
	}

	function ensurePanel() {
		if (host && host.isConnected) { return; }
		host = document.createElement('div');
		host.id = 'bepluspb-lcp-panel';
		root = host.attachShadow({ mode: 'closed' });
		root.appendChild(el('style', { text: CSS }));
		document.body.appendChild(host);
	}

	function closePanel() {
		if (host && host.parentNode) { host.parentNode.removeChild(host); }
		host = null;
		root = null;
	}

	function kindLabel(kind) {
		return { img: t('kindImg'), bg: t('kindBg'), video: t('kindVideo'), text: t('kindText'), other: t('kindOther') }[kind] || kind;
	}

	function usable(r) { return !!(r && r.url && r.kind !== 'text'); }

	function advice(r) {
		var lines = [];
		if (r.kind === 'text') { lines.push({ cls: 'warn', text: t('textLcp') }); return lines; }
		if (r.prioritized) { lines.push({ cls: 'ok', text: t('prioritized') }); }
		if (r.kind === 'img') {
			if (r.lazy) { lines.push({ cls: 'warn', text: t('lazy') }); }
			if (r.fetchpriority !== 'high' && !r.prioritized) { lines.push({ cls: 'warn', text: t('noPriority') }); }
		}
		if (r.kind === 'bg' || r.kind === 'video') { lines.push({ cls: 'warn', text: t('lateBg') }); }
		if (r.time > SLOW_MS) { lines.push({ cls: 'warn', text: t('slow') }); }
		return lines;
	}

	function viewportSection(device, r) {
		var children = [el('h3', { text: t(device) })];
		if (r === 'pending') {
			children.push(el('p', { class: 'note', text: t('measuring') }));
		} else if (r && r.blocked) {
			children.push(el('p', { class: 'warn', text: t('popupBlocked') }));
		} else if (!r || r.failed) {
			children.push(el('p', { class: 'note', text: t('notMeasured') }));
		} else if (r.unsupported) {
			children.push(el('p', { class: 'err', text: t('unsupported') }));
		} else if (r.none) {
			children.push(el('p', { class: 'note', text: t('notMeasured') }));
		} else {
			var dl = el('dl', null, [
				el('dt', { text: t('element') }), el('dd', { text: kindLabel(r.kind) + (r.label ? ' — ' + r.label : '') }),
				r.url ? el('dt', { text: t('image') }) : null, r.url ? el('dd', { text: r.url }) : null,
				el('dt', { text: t('time') }), el('dd', { text: r.time + ' ms' })
			]);
			children.push(dl);
			var list = el('ul');
			advice(r).forEach(function (a) { list.appendChild(el('li', { class: a.cls, text: a.text })); });
			if (list.childNodes.length) { children.push(list); }
		}
		return el('section', null, children);
	}

	function sameImage(a, b) { return a.url === b.url && a.srcset === b.srcset && a.sizes === b.sizes; }

	function entryFor(r, device, scope) {
		return { url: r.url, scope: scope, device: device, srcset: r.srcset, sizes: r.sizes };
	}

	/** Lines to save: one for both screens when they use the same image, else one per screen. */
	function buildEntries(scope) {
		var m = state.results.mobile;
		var d = state.results.desktop;
		var list = [];
		if (usable(m) && usable(d) && sameImage(m, d)) {
			list.push(entryFor(d, 'all', scope));
		} else {
			if (usable(d)) { list.push(entryFor(d, 'desktop', scope)); }
			if (usable(m)) { list.push(entryFor(m, 'mobile', scope)); }
		}
		return list;
	}

	function save(entries, status, button) {
		button.disabled = true;
		status.className = 'note';
		status.textContent = t('applying');
		var body = new FormData();
		body.append('action', 'bepluspb_lcp_apply');
		body.append('nonce', cfg.nonce);
		body.append('entries', JSON.stringify(entries));
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json().catch(function () { return { success: false, data: { message: String(response.status) } }; }); })
			.then(function (json) {
				if (json && json.success) {
					status.className = 'ok';
					status.textContent = (json.data && json.data.message ? json.data.message + ' ' : '') + t('applied');
				} else {
					status.className = 'err';
					status.textContent = t('error') + ' ' + (json && json.data && json.data.message ? json.data.message : '');
					button.disabled = false;
				}
			})
			.catch(function (e) {
				status.className = 'err';
				status.textContent = t('error') + ' ' + (e && e.message ? e.message : '');
				button.disabled = false;
			});
	}

	function actions() {
		var scopes = cfg.isHome
			? [['home', t('scopeHome')], ['all', t('scopeAll')]]
			: [[cfg.path, t('scopeThis')], ['all', t('scopeAll')]];
		// Pages of one type that share the LCP image (a banner in the template): offered when the type is known.
		if (!cfg.isHome && cfg.pageType) { scopes.splice(1, 0, [cfg.pageType, t('scopeType') + ' (' + cfg.pageType + ')']); }
		var select = el('select', { 'aria-label': t('scopeThis') });
		scopes.forEach(function (s) { select.appendChild(el('option', { value: s[0], text: s[1] })); });
		var status = el('p', { class: 'note' });
		var apply = el('button', { type: 'button', text: t('apply') });
		apply.addEventListener('click', function () {
			var entries = buildEntries(select.value);
			if (!entries.length) { status.className = 'warn'; status.textContent = t('noImage'); return; }
			save(entries, status, apply);
		});
		var again = el('button', { type: 'button', class: 'secondary', text: t('again') });
		again.addEventListener('click', start);
		var settings = el('a', { class: 'btn secondary', href: cfg.settingsUrl, target: '_blank', rel: 'noopener', text: t('settings') });

		var box = el('div', null, [
			el('p', { class: 'note', text: t('replaceNote') }),
			el('div', { class: 'row' }, [select, apply]),
			status,
			el('div', { class: 'row' }, [again, settings])
		]);
		return usable(state.results.mobile) || usable(state.results.desktop)
			? box
			: el('div', null, [el('p', { class: 'warn', text: t('noImage') }), el('div', { class: 'row' }, [again, settings])]);
	}

	function render() {
		ensurePanel();
		var close = el('button', { type: 'button', class: 'secondary', 'aria-label': t('close'), text: '×' });
		close.addEventListener('click', closePanel);
		var current = state.current;
		var other = current === 'desktop' ? 'mobile' : 'desktop';
		var card = el('div', { class: 'card', role: 'dialog', 'aria-label': t('title') }, [
			el('h2', null, [el('span', { text: t('title') }), close]),
			el('p', { class: 'note', text: cfg.optimized ? t('measuredOptimized') : t('measuredOriginal') }),
			viewportSection(current, state.results[current]),
			viewportSection(other, state.results[other])
		]);
		if (state.results[current] !== 'pending' && state.results[other] !== 'pending') { card.appendChild(actions()); }
		// Replace the previous card (keep the <style> first child).
		while (root.childNodes.length > 1) { root.removeChild(root.lastChild); }
		root.appendChild(card);
	}

	// ---------------------------------------------------------------------------------
	// Flow
	// ---------------------------------------------------------------------------------

	function start() {
		state.current = deviceOfWidth(window.innerWidth);
		var other = state.current === 'desktop' ? 'mobile' : 'desktop';
		state.results = {};
		state.results[state.current] = 'pending';
		state.results[other] = 'pending';

		// Opened right here, inside the click, so the browser lets it through; it measures
		// while this window does.
		var probe = openProbe(other);
		render();

		whenLoaded(function () {
			window.setTimeout(function () {
				readLcp(function (result) {
					state.results[state.current] = result;
					render();
					if (result.unsupported) {
						if (probe) { try { probe.win.close(); } catch (e) { /* ignore */ } }
						state.results[other] = result;
						render();
						return;
					}
					waitProbe(probe, function (otherResult) {
						state.results[other] = otherResult;
						render();
					});
				});
			}, 300);
		});
	}

	function bind() {
		var link = document.querySelector('#wp-admin-bar-bepluspb-lcp > a');
		if (!link) { return; }
		link.addEventListener('click', function (event) {
			event.preventDefault();
			start();
		});
	}

	document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && host) { closePanel(); } });
	bind();
})();
