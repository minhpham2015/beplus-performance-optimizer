/**
 * Critical CSS generator (administrators only).
 *
 * Admin bar → "Generate Critical CSS (this page type)" works out which CSS rules the first screen
 * needs, in the administrator's own browser: the page is loaded twice in small same-origin
 * off-screen frames (phone-sized and desktop-sized), every stylesheet rule is tested against the
 * elements that are inside the frame's first screen, and the two results are merged in source
 * order. The administrator then saves it for the current page type.
 *
 * What is kept: rules whose selector matches a first-screen element (pseudo-elements and
 * hover/focus/active states are ignored when matching), inside the @media / @supports / @layer /
 * @container blocks they were in (only the ones that apply at that size are considered), plus the
 * @font-face and @keyframes the kept rules use. Relative url() references are made absolute
 * (the CSS is printed in the page, not next to the stylesheet). Rules for the admin bar and for
 * logged-in users are left out: visitors never see them.
 *
 * Nothing is sent anywhere except the admin-ajax.php of this site, and only after the
 * administrator presses "Save". When an analysis FAILS, one extra GET of this very page address
 * (same site, same cookies, headers only) is made to tell why (redirect, frame-blocking headers).
 * Page data is only ever written with textContent.
 */
(function () {
	'use strict';

	var cfg = window.bepluspbCcss;
	if (!cfg || !document.body) { return; }

	var PROBE_PREFIX = 'bepluspb-ccss-probe:';
	var VIEWPORTS = { mobile: { w: 390, h: 844 }, desktop: { w: 1366, h: 800 } };
	var SETTLE_MS = 1500;
	var HELLO_MS = 20000;  // the script in the measuring frame must report in within this
	var RESULT_MS = 60000; // then the analysis must arrive within this
	var BIG_BYTES = 60000;

	function t(key) { return (cfg.i18n && cfg.i18n[key]) || key; }

	/** A waiting limit in ms; `cfg.timing` can only tighten/loosen it within sane bounds (used by the tests). */
	function limit(key, fallback) {
		var v = cfg.timing && cfg.timing[key];
		return typeof v === 'number' && v >= 500 && v <= 180000 ? v : fallback;
	}

	/** How long this very page took to load: a slow connection or server makes the measuring frames slow too. */
	function ownLoadMs() {
		try {
			var nav = performance.getEntriesByType('navigation')[0];
			if (nav && nav.loadEventEnd > 0) { return nav.loadEventEnd; }
		} catch (e) { /* no navigation timing */ }
		return 0;
	}

	/** A default waiting limit stretched to three times the page's own load time (never above 3 minutes). */
	function adaptive(base) { return Math.min(180000, Math.max(base, ownLoadMs() * 3 + 10000)); }

	function deviceOfWidth(width) { return width >= 768 ? 'desktop' : 'mobile'; }

	// ---------------------------------------------------------------------------------
	// Analysis (runs inside each measuring frame)
	// ---------------------------------------------------------------------------------

	var SKIP_SHEET_IDS = { 'admin-bar-css': 1, 'dashicons-css': 1, 'bepluspb-critical-css': 1 };
	var EXCLUDED_SELECTOR = /#wpadminbar|\.admin-bar\b|\.logged-in\b|\.customize-support\b|\.wp-admin\b|#bepluspb/;
	var NON_VISUAL = { SCRIPT: 1, STYLE: 1, LINK: 1, META: 1, NOSCRIPT: 1, TEMPLATE: 1, TITLE: 1, HEAD: 1, BASE: 1, IFRAME: 1 };

	function mediaMatches(text) {
		try { return window.matchMedia(text).matches; } catch (e) { return true; }
	}

	function onFirstScreen(el, vw, vh) {
		var r = el.getBoundingClientRect();
		return r.bottom >= 0 && r.top <= vh && r.right >= 0 && r.left <= vw;
	}

	/**
	 * Elements that matter for the first screen: the ones drawn inside it (html and body always),
	 * plus the TOP of every hidden subtree whose parent is drawn inside it. The rule that hides
	 * an element (a menu, the desktop bar on a phone) is needed too: without it the element would
	 * show up until the full stylesheet arrives. Its descendants are not candidates, so the
	 * rules of a hidden mega-menu's contents do not end up in the critical CSS.
	 */
	function collectVisible(vw, vh) {
		var out = [document.documentElement, document.body];
		var all = document.body.querySelectorAll('*');
		for (var i = 0; i < all.length; i++) {
			var el = all[i];
			if (NON_VISUAL[el.tagName] || el.closest('#wpadminbar, #bepluspb-lcp-panel, #bepluspb-ccss-panel')) { continue; }
			if (el.getClientRects().length === 0) {
				var parent = el.parentElement;
				if (parent && parent !== document.body && parent.getClientRects().length > 0 && onFirstScreen(parent, vw, vh)) { out.push(el); }
				continue;
			}
			if (onFirstScreen(el, vw, vh)) { out.push(el); }
		}
		return out;
	}

	/** Split a selector list at top-level commas (not inside (), [] or strings). */
	function splitSelectors(text) {
		var parts = [];
		var depth = 0;
		var quote = '';
		var start = 0;
		for (var i = 0; i < text.length; i++) {
			var c = text.charAt(i);
			if (c === '\\') { i++; continue; }
			if (quote) { if (c === quote) { quote = ''; } continue; }
			if (c === '"' || c === "'") { quote = c; } else if (c === '(' || c === '[') { depth++; } else if (c === ')' || c === ']') { depth--; } else if (c === ',' && depth === 0) {
				parts.push(text.slice(start, i).trim());
				start = i + 1;
			}
		}
		parts.push(text.slice(start).trim());
		return parts.filter(Boolean);
	}

	/** Selector without pseudo-elements and dynamic states, so it can be tested against the DOM as it is. */
	function baseSelector(selector) {
		var s = selector
			.replace(/::?(?:before|after|first-line|first-letter|selection|placeholder|marker|backdrop|file-selector-button|-webkit-[\w-]+|-moz-[\w-]+|-ms-[\w-]+)(?:\([^)]*\))?/gi, '')
			.replace(/:(?:focus-visible|focus-within|target-within|hover|focus|active|visited|target)(?![\w-])/gi, '')
			.trim();
		return s === '' ? '*' : s;
	}

	function absolutizeUrls(css, base) {
		return css.replace(/url\(\s*(?:"([^"]*)"|'([^']*)'|([^)\s]*))\s*\)/gi, function (match, dq, sq, bare) {
			var raw = dq !== undefined ? dq : (sq !== undefined ? sq : bare);
			if (!raw || /^(?:data:|#|about:|blob:)/i.test(raw)) { return match; }
			try {
				var u = new URL(raw, base);
				if (u.protocol !== 'http:' && u.protocol !== 'https:') { return match; }
				var out = u.origin === window.location.origin ? u.pathname + u.search : u.href;
				return 'url("' + out.replace(/"/g, '%22') + '")';
			} catch (e) { return match; }
		});
	}

	function familyNames(value) {
		return String(value || '').split(',').map(function (f) { return f.trim().replace(/^["']|["']$/g, '').toLowerCase(); }).filter(Boolean);
	}

	function analyse() {
		var vw = window.innerWidth;
		var vh = window.innerHeight;
		var visible = collectVisible(vw, vh);
		var matchCache = {};
		var usedFamilies = {};
		var usedAnimations = {};
		var items = [];
		var fontFaces = [];
		var keyframes = [];
		var skippedSheets = 0;
		var hasLayerRules = typeof CSSLayerBlockRule !== 'undefined';
		var hasContainerRules = typeof CSSContainerRule !== 'undefined';

		function selectorOnFirstScreen(selector) {
			var base = baseSelector(selector);
			if (Object.prototype.hasOwnProperty.call(matchCache, base)) { return matchCache[base]; }
			var result;
			try {
				if (!document.querySelector(base)) {
					result = false; // Fast reject: no such element anywhere.
				} else {
					result = false;
					for (var i = 0; i < visible.length; i++) {
						if (visible[i].matches(base)) { result = true; break; }
					}
				}
			} catch (e) {
				result = true; // A selector this browser cannot test: keep it, the safe side.
			}
			matchCache[base] = result;
			return result;
		}

		function noteUsage(style) {
			familyNames(style.fontFamily).forEach(function (f) { usedFamilies[f] = true; });
			// A font stack kept in a custom property (--font: "Inter", sans-serif).
			for (var i = 0; i < style.length; i++) {
				var name = style[i];
				if (name.indexOf('--') === 0) { familyNames(style.getPropertyValue(name)).forEach(function (f) { usedFamilies[f] = true; }); }
			}
			familyNames(style.animationName).forEach(function (a) { usedAnimations[a] = true; });
		}

		function styleRule(rule, path, wrappers, base) {
			var selectorText = rule.selectorText || '';
			if (!selectorText) { return; }
			var parts = splitSelectors(selectorText).filter(function (p) { return !EXCLUDED_SELECTOR.test(p); });
			var kept = parts.filter(selectorOnFirstScreen);
			if (!kept.length) { return; }
			var css;
			if (!rule.style.cssText && !(rule.cssRules && rule.cssRules.length)) { return; } // Nothing left after the browser dropped invalid declarations.
			if (rule.cssRules && rule.cssRules.length) {
				css = rule.cssText; // Native nesting: keep the rule whole.
			} else {
				css = kept.join(',') + '{' + rule.style.cssText + '}';
			}
			noteUsage(rule.style);
			items.push({ p: path, w: wrappers, c: absolutizeUrls(css, base) });
		}

		function walk(rules, prefix, wrappers, base) {
			for (var i = 0; i < rules.length; i++) {
				var rule = rules[i];
				var path = prefix.concat(i);
				var id = path.join('/');
				var type = rule.type;
				if (type === 1) {
					styleRule(rule, path, wrappers, base);
				} else if (type === 4) { // @media
					if (mediaMatches(rule.media.mediaText)) {
						walk(rule.cssRules, path, wrappers.concat([{ id: id, h: '@media ' + rule.media.mediaText }]), base);
					}
				} else if (type === 12) { // @supports
					var ok = true;
					try { ok = window.CSS && CSS.supports ? CSS.supports(rule.conditionText) : true; } catch (e) { ok = true; }
					if (ok) { walk(rule.cssRules, path, wrappers.concat([{ id: id, h: '@supports ' + rule.conditionText }]), base); }
				} else if (type === 5) { // @font-face
					fontFaces.push({ p: path, w: wrappers, c: absolutizeUrls(rule.cssText, base), family: familyNames(rule.style.getPropertyValue('font-family'))[0] || '' });
				} else if (type === 7) { // @keyframes
					keyframes.push({ p: path, w: wrappers, c: rule.cssText, name: String(rule.name || '').toLowerCase() });
				} else if (type === 3) { // @import: its sheet is part of the page
					try {
						if (rule.styleSheet && (!rule.media || !rule.media.mediaText || mediaMatches(rule.media.mediaText))) {
							walk(rule.styleSheet.cssRules, path, wrappers, rule.styleSheet.href || base);
						}
					} catch (e) { skippedSheets++; }
				} else if (hasLayerRules && rule instanceof CSSLayerBlockRule) {
					walk(rule.cssRules, path, wrappers.concat([{ id: id, h: '@layer ' + rule.name }]), base);
				} else if (hasContainerRules && rule instanceof CSSContainerRule) {
					walk(rule.cssRules, path, wrappers.concat([{ id: id, h: '@container ' + rule.conditionText }]), base);
				} else if (typeof CSSLayerStatementRule !== 'undefined' && rule instanceof CSSLayerStatementRule) {
					items.push({ p: path, w: wrappers, c: rule.cssText }); // Declares layer order: always needed.
				}
			}
		}

		var sheets = document.styleSheets;
		for (var s = 0; s < sheets.length; s++) {
			var sheet = sheets[s];
			var owner = sheet.ownerNode;
			if (sheet.disabled || (owner && owner.id && SKIP_SHEET_IDS[owner.id])) { continue; }
			if (sheet.media && sheet.media.mediaText && !mediaMatches(sheet.media.mediaText)) { continue; }
			var rules;
			try { rules = sheet.cssRules; } catch (e) { skippedSheets++; continue; } // Another domain: not readable.
			walk(rules, [s], [], sheet.href || document.baseURI);
		}

		fontFaces.forEach(function (f) { if (f.family && usedFamilies[f.family]) { items.push({ p: f.p, w: f.w, c: f.c }); } });
		keyframes.forEach(function (k) { if (k.name && usedAnimations[k.name]) { items.push({ p: k.p, w: k.w, c: k.c }); } });

		var bytes = 0;
		items.forEach(function (it) { bytes += it.c.length; });
		return { items: items, rules: items.length, bytes: bytes, skippedSheets: skippedSheets, width: vw, height: vh, elements: visible.length };
	}

	function whenLoaded(fn) {
		if (document.readyState === 'complete') { fn(); } else { window.addEventListener('load', fn, { once: true }); }
	}

	// ---------------------------------------------------------------------------------
	// Measuring frame: this same script, running inside an off-screen copy of the page
	// ---------------------------------------------------------------------------------

	if (window.name && window.name.indexOf(PROBE_PREFIX) === 0) {
		var token = window.name.slice(PROBE_PREFIX.length);
		/** Tell the parent how far we got: hello (script started), loaded, then result or error. */
		var send = function (stage, payload) {
			try {
				window.parent.postMessage({ bepluspbCcss: token, stage: stage, payload: payload || null }, window.location.origin);
				return true;
			} catch (e) { return false; }
		};
		send('hello', { readyState: document.readyState });
		whenLoaded(function () {
			send('loaded', null);
			window.setTimeout(function () {
				var result;
				try { result = analyse(); } catch (e) {
					send('error', { message: String(e && e.message || e), stack: String(e && e.stack || '').slice(0, 600) });
					return;
				}
				if (!send('result', result)) { send('error', { message: 'the result could not be sent to the page that asked for it' }); }
			}, SETTLE_MS);
		});
		return;
	}

	/**
	 * Load this page in an off-screen same-origin frame of the given size and collect its analysis.
	 *
	 * The result is either the analysis or { failed: true, kind, info, waited } where kind says WHY:
	 *  not-loaded    the frame never finished loading (hung server/connection)
	 *  unreadable    the frame holds a page we cannot read (redirected to another origin, or framing refused)
	 *  no-hello      the page loaded but our script never reported (blocked, delayed, window.name reset)
	 *  slow          our script started but the page had not finished loading in time
	 *  analysis-slow the page loaded but the analysis did not arrive in time
	 *  error         the analysis threw inside the page (message, stack)
	 */
	function analyseInFrame(device, callback) {
		var size = VIEWPORTS[device];
		var frameToken = String(Date.now()) + Math.random().toString(36).slice(2);
		var frame = document.createElement('iframe');
		var started = Date.now();
		var finished = false;
		var timer = null;
		var info = { helloAt: null, helloState: '', loadedAt: null, frameLoadAt: null, readable: null, frameHref: '', readyState: '' };

		function since() { return Date.now() - started; }

		/** What the parent can see of the frame: its address and state, or that it cannot read it at all. */
		function inspect() {
			try {
				info.frameHref = frame.contentWindow.location.href;
				info.readyState = frame.contentDocument ? frame.contentDocument.readyState : '';
				info.readable = true;
			} catch (e) {
				info.readable = false;
			}
		}
		function finish(result) {
			if (finished) { return; }
			finished = true;
			window.removeEventListener('message', onMessage);
			window.clearTimeout(timer);
			if (frame.parentNode) { frame.parentNode.removeChild(frame); }
			callback(result);
		}
		function fail(kind, extra) {
			if (finished) { return; }
			inspect();
			var result = { failed: true, kind: kind, info: info, waited: since() };
			Object.keys(extra || {}).forEach(function (k) { result[k] = extra[k]; });
			finish(result);
		}
		function onMessage(event) {
			var data = event.data;
			if (event.origin !== window.location.origin || event.source !== frame.contentWindow ||
				!data || data.bepluspbCcss !== frameToken) { return; }
			var payload = data.payload || {};
			if (data.stage === 'hello') {
				info.helloAt = since();
				info.helloState = payload.readyState || '';
				window.clearTimeout(timer);
				timer = window.setTimeout(function () { fail(info.loadedAt === null ? 'slow' : 'analysis-slow'); }, limit('result', adaptive(RESULT_MS)));
			} else if (data.stage === 'loaded') {
				info.loadedAt = since();
			} else if (data.stage === 'error') {
				fail('error', { message: String(payload.message || ''), stack: String(payload.stack || '') });
			} else if (data.stage === 'result' && data.payload) {
				finish(data.payload);
			}
		}
		window.addEventListener('message', onMessage);
		frame.addEventListener('load', function () {
			if (info.frameLoadAt === null) { info.frameLoadAt = since(); }
			inspect();
		});
		timer = window.setTimeout(function () {
			inspect();
			fail(info.frameLoadAt === null ? 'not-loaded' : (info.readable === false ? 'unreadable' : 'no-hello'));
		}, limit('hello', adaptive(HELLO_MS)));

		frame.name = PROBE_PREFIX + frameToken;
		frame.setAttribute('aria-hidden', 'true');
		frame.tabIndex = -1;
		frame.title = '';
		frame.style.cssText = 'position:fixed;top:0;left:-10000px;border:0;pointer-events:none;width:' + size.w + 'px;height:' + size.h + 'px;';
		frame.src = window.location.href.split('#')[0];
		document.body.appendChild(frame);
	}

	/**
	 * After a failure: ONE GET of this very page address (same site, same cookies, headers only; the body is
	 * never read) to see what a request for it gets back. Explains a redirect or a frame-blocking header.
	 */
	function diagnose(callback) {
		var lines = [];
		var conclusion = '';
		var t0 = Date.now();
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var guard = window.setTimeout(function () { if (controller) { controller.abort(); } }, 15000);
		fetch(window.location.href.split('#')[0], { credentials: 'same-origin', cache: 'no-store', redirect: 'manual', signal: controller ? controller.signal : undefined })
			.then(function (response) {
				var ms = Date.now() - t0;
				if (response.type === 'opaqueredirect') {
					lines.push('GET of this page: it answers with a REDIRECT (' + ms + ' ms)');
					conclusion = 'redirect';
				} else {
					var xfo = response.headers.get('x-frame-options');
					var csp = response.headers.get('content-security-policy') || '';
					var ancestors = csp.split(';').map(function (d) { return d.trim(); }).filter(function (d) { return /^frame-ancestors\b/i.test(d); })[0] || '';
					lines.push('GET of this page: HTTP ' + response.status + ' in ' + ms + ' ms');
					lines.push('X-Frame-Options: ' + (xfo || '(none)'));
					lines.push('CSP frame-ancestors: ' + (ancestors || '(none)'));
					lines.push('server: ' + (response.headers.get('server') || '(not shown)'));
					if ((xfo && /^\s*deny\s*$/i.test(xfo)) || (ancestors && !/'self'|\*/.test(ancestors))) { conclusion = 'framing-refused'; }
				}
				if (controller) { controller.abort(); } // headers are enough: do not download the page again
			})
			.catch(function (e) { if (!lines.length) { lines.push('GET of this page failed: ' + (e && e.message ? e.message : e)); } })
			.then(function () { window.clearTimeout(guard); callback({ lines: lines, conclusion: conclusion }); });
	}

	// ---------------------------------------------------------------------------------
	// Merge the two analyses
	// ---------------------------------------------------------------------------------

	function comparePaths(a, b) {
		for (var i = 0; i < Math.min(a.length, b.length); i++) {
			if (a[i] !== b[i]) { return a[i] - b[i]; }
		}
		return a.length - b.length;
	}

	/** Union of the items of every analysis, in source order, re-wrapped in their at-rule blocks. */
	function mergeToCss(results) {
		var byPath = {};
		results.forEach(function (r) {
			if (!r || !r.items) { return; }
			r.items.forEach(function (it) { byPath[it.p.join('/')] = it; });
		});
		var list = Object.keys(byPath).map(function (k) { return byPath[k]; });
		list.sort(function (a, b) { return comparePaths(a.p, b.p); });

		var out = '';
		var open = []; // currently open wrappers: { id, h }
		list.forEach(function (it) {
			var common = 0;
			while (common < open.length && common < it.w.length && open[common].id === it.w[common].id) { common++; }
			while (open.length > common) { out += '}'; open.pop(); }
			for (var i = common; i < it.w.length; i++) { out += it.w[i].h + '{'; open.push(it.w[i]); }
			out += it.c;
		});
		while (open.length) { out += '}'; open.pop(); }
		return out;
	}

	// ---------------------------------------------------------------------------------
	// Panel (closed shadow root)
	// ---------------------------------------------------------------------------------

	var PANEL_CSS = [
		':host{all:initial}',
		'.card{box-sizing:border-box;position:fixed;inset-block-end:16px;inset-inline-end:16px;z-index:2147483000;width:min(440px,calc(100vw - 32px));max-height:calc(100vh - 32px);overflow:auto;background:#fff;color:#1d2327;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.25);font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:14px 16px}',
		'h2{margin:0 0 8px;font-size:15px;font-weight:600;display:flex;justify-content:space-between;align-items:center}',
		'.note{color:#50575e;font-size:12px;margin:0 0 8px}',
		'section{border-top:1px solid #dcdcde;padding:8px 0}',
		'h3{margin:0 0 2px;font-size:13px;font-weight:600}',
		'.warn{color:#8a4b00;margin:4px 0}.ok{color:#007017}.err{color:#b32d2e}',
		'button{font:inherit;cursor:pointer;border:1px solid #2271b1;background:#2271b1;color:#fff;border-radius:4px;padding:5px 12px}',
		'button.secondary,a.btn.secondary{background:#fff;color:#2271b1}',
		'a.btn{font:inherit;border:1px solid #2271b1;border-radius:4px;padding:5px 12px;text-decoration:none;display:inline-block}',
		'button[disabled]{opacity:.6;cursor:default}',
		'textarea{box-sizing:border-box;width:100%;height:140px;font:11px/1.4 ui-monospace,Menlo,Consolas,monospace;margin-top:6px}',
		'.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}'
	].join('');

	var host = null;
	var root = null;
	var state = null;

	function el(tag, props, children) {
		var node = document.createElement(tag);
		if (props) {
			Object.keys(props).forEach(function (k) {
				if (k === 'text') { node.textContent = props[k]; } else if (k === 'class') { node.className = props[k]; } else if (k === 'value') { node.value = props[k]; } else { node.setAttribute(k, props[k]); }
			});
		}
		(children || []).forEach(function (c) { if (c) { node.appendChild(c); } });
		return node;
	}

	function ensurePanel() {
		if (host && host.isConnected) { return; }
		host = document.createElement('div');
		host.id = 'bepluspb-ccss-panel';
		root = host.attachShadow({ mode: 'closed' });
		root.appendChild(el('style', { text: PANEL_CSS }));
		document.body.appendChild(host);
	}

	function closePanel() {
		if (host && host.parentNode) { host.parentNode.removeChild(host); }
		host = null;
		root = null;
	}

	function kb(bytes) { return bytes >= 1024 ? (bytes / 1024).toFixed(1) + ' KB' : bytes + ' B'; }

	/** The reason an analysis failed, in words (the exact numbers are in the technical details). */
	function failureText(r) {
		var keys = { 'not-loaded': 'failNotLoaded', 'slow': 'failSlow', 'unreadable': 'failUnreadable', 'no-hello': 'failNoHello', 'error': 'failError', 'analysis-slow': 'failAnalysisSlow' };
		var key = r && keys[r.kind];
		if (!key) { return t('failed'); }
		var text = t(key).replace('%d', String(Math.round((r.waited || 0) / 1000)));
		return r.kind === 'error' && r.message ? text + ' ' + r.message : text;
	}

	/** Everything known about a failure, as plain text the administrator can copy and send. */
	function detailsText() {
		var lines = ['Beplus Performance Booster - Critical CSS Generator diagnostics', 'page: ' + window.location.href.split('#')[0], 'time: ' + new Date().toISOString(), 'browser: ' + navigator.userAgent];
		var c = navigator.connection;
		if (c) { lines.push('network (as the browser sees it): ' + (c.effectiveType || '?') + ', downlink ' + c.downlink + ' Mbit/s, rtt ' + c.rtt + ' ms' + (c.saveData ? ', data saver on' : '')); }
		['mobile', 'desktop'].forEach(function (d) {
			var r = state.results[d];
			if (r && r.items) {
				lines.push('', d + ': ok, ' + r.rules + ' rules, ' + r.bytes + ' bytes, ' + r.skippedSheets + ' unreadable stylesheets, ' + r.elements + ' first-screen elements');
			} else if (!r || r.failed) {
				var i = (r && r.info) || {};
				lines.push('', d + ': FAILED (' + ((r && r.kind) || 'unknown') + ') after ' + ((r && r.waited) || 0) + ' ms');
				lines.push('  script in the frame reported in: ' + (i.helloAt === null || i.helloAt === undefined ? 'never' : i.helloAt + ' ms (readyState ' + i.helloState + ')'));
				lines.push('  frame load event: ' + (i.frameLoadAt === null || i.frameLoadAt === undefined ? 'never' : i.frameLoadAt + ' ms'));
				lines.push('  page finished loading (said by the script): ' + (i.loadedAt === null || i.loadedAt === undefined ? 'no' : i.loadedAt + ' ms'));
				lines.push('  frame readable: ' + (i.readable === null || i.readable === undefined ? 'unknown' : (i.readable ? 'yes, ' + i.frameHref + ' (readyState ' + i.readyState + ')' : 'NO (another origin, or blocked)')));
				if (r && r.message) { lines.push('  error: ' + r.message); }
				if (r && r.stack) { lines.push('  stack: ' + r.stack.split('\n').slice(0, 3).join(' | ')); }
			}
		});
		lines.push('', 'check of this page address:');
		if (state.diag) { state.diag.lines.forEach(function (l) { lines.push('  ' + l); }); } else { lines.push('  (running...)'); }
		return lines.join('\n');
	}

	/** Conclusion + copyable details, shown whenever something failed. */
	function diagnosticsBox() {
		var failed = [state.results.mobile, state.results.desktop].some(function (r) { return !r || r.failed; });
		if (!failed) { return null; }
		var box = el('section', null, []);
		var conclusion = state.diag && state.diag.conclusion;
		if (conclusion === 'framing-refused') { box.appendChild(el('p', { class: 'err', text: t('diagFramingRefused') })); }
		if (conclusion === 'redirect') { box.appendChild(el('p', { class: 'warn', text: t('diagRedirect') })); }
		box.appendChild(el('h3', { text: t('details') }));
		var area = el('textarea', { readonly: 'readonly', 'data-role': 'details', 'aria-label': t('details') });
		area.value = detailsText();
		var copy = el('button', { type: 'button', class: 'secondary', text: t('copy') });
		copy.addEventListener('click', function () {
			area.select();
			var done = function () { copy.textContent = t('copied'); };
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(area.value).then(done, function () { try { document.execCommand('copy'); done(); } catch (e) { /* the text is selected: Ctrl+C */ } }); } else { try { document.execCommand('copy'); done(); } catch (e2) { /* the text is selected: Ctrl+C */ } }
		});
		box.appendChild(area);
		box.appendChild(el('div', { class: 'row' }, [copy]));
		return box;
	}

	function deviceSection(device, r) {
		var children = [el('h3', { text: t(device) })];
		if (r === 'pending') {
			children.push(el('p', { class: 'note', text: t('working') }));
		} else if (!r || r.failed) {
			children.push(el('p', { class: 'warn', text: failureText(r) }));
		} else {
			children.push(el('p', { class: 'note', text: r.rules + ' ' + t('rules') + ' · ' + kb(r.bytes) }));
			if (r.skippedSheets) { children.push(el('p', { class: 'warn', text: r.skippedSheets + ' ' + t('skipped') })); }
		}
		return el('section', null, children);
	}

	function render() {
		ensurePanel();
		var close = el('button', { type: 'button', class: 'secondary', 'aria-label': t('close'), text: '×' });
		close.addEventListener('click', closePanel);
		var card = el('div', { class: 'card', role: 'dialog', 'aria-label': t('title') }, [
			el('h2', null, [el('span', { text: t('title') }), close]),
			el('p', { class: 'note', text: t('optimizedNote') }),
			deviceSection('mobile', state.results.mobile),
			deviceSection('desktop', state.results.desktop)
		]);

		var done = state.results.mobile !== 'pending' && state.results.desktop !== 'pending';
		if (done) { card.appendChild(summary()); }
		while (root.childNodes.length > 1) { root.removeChild(root.lastChild); }
		root.appendChild(card);
	}

	function summary() {
		var css = state.css;
		var bytes = css.length;
		var usableResults = [state.results.mobile, state.results.desktop].filter(function (r) { return r && r.items; });
		var box = el('div', null, []);

		if (!usableResults.length) {
			box.appendChild(el('p', { class: 'err', text: t('nothing') }));
			var why = diagnosticsBox();
			if (why) { box.appendChild(why); }
			box.appendChild(actionsRow(false));
			return box;
		}

		if (state.note) { box.appendChild(el('p', { class: 'ok', text: state.note })); }
		box.appendChild(el('section', null, [
			el('h3', { text: t('total') + ': ' + kb(bytes) }),
			el('p', { class: 'note', text: t('target') + ': ' + cfg.label + (cfg.storedBytes ? ' — ' + t('stored') : '') })
		]));
		if (!cfg.enabled) { box.appendChild(el('p', { class: 'warn', text: t('needEnable') })); }
		if (!cfg.nonBlocking) { box.appendChild(el('p', { class: 'warn', text: t('needDefer') })); }
		if (cfg.inlineAll) { box.appendChild(el('p', { class: 'warn', text: t('inlineAll') })); }
		if (bytes > BIG_BYTES) { box.appendChild(el('p', { class: 'warn', text: t('big') })); }
		var tooBig = bytes > cfg.maxBytes;
		if (tooBig) { box.appendChild(el('p', { class: 'err', text: t('tooBig') })); }

		var status = el('p', { class: 'note' });
		var preview = el('textarea', { readonly: 'readonly', 'aria-label': t('preview') });
		preview.value = css;
		preview.hidden = true;
		var toggle = el('button', { type: 'button', class: 'secondary', text: t('preview') });
		toggle.addEventListener('click', function () { preview.hidden = !preview.hidden; });

		var saveBtn = el('button', { type: 'button', text: t('save') });
		if (tooBig || !css) { saveBtn.disabled = true; }
		saveBtn.addEventListener('click', function () { save(css, status, saveBtn); });

		box.appendChild(el('div', { class: 'row' }, [saveBtn, toggle]));
		box.appendChild(status);
		box.appendChild(preview);
		var partial = diagnosticsBox();
		if (partial) { box.appendChild(partial); }
		box.appendChild(actionsRow(true));
		return box;
	}

	function actionsRow() {
		var again = el('button', { type: 'button', class: 'secondary', text: t('again') });
		again.addEventListener('click', start);
		var here = el('button', { type: 'button', class: 'secondary', text: t('here') });
		here.addEventListener('click', analyseHere);
		var settings = el('a', { class: 'btn secondary', href: cfg.settingsUrl, target: '_blank', rel: 'noopener', text: t('settings') });
		return el('div', { class: 'row' }, [again, here, settings]);
	}

	function save(css, status, button) {
		button.disabled = true;
		status.className = 'note';
		status.textContent = t('saving');
		var body = new FormData();
		body.append('action', 'bepluspb_ccss_save');
		body.append('nonce', cfg.nonce);
		body.append('key', cfg.key);
		body.append('source', 'generated');
		body.append('css', css);
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json().catch(function () { return { success: false, data: { message: String(response.status) } }; }); })
			.then(function (json) {
				if (json && json.success) {
					status.className = 'ok';
					status.textContent = t('saved');
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

	// ---------------------------------------------------------------------------------
	// Flow
	// ---------------------------------------------------------------------------------

	function start() {
		state = { results: { mobile: 'pending', desktop: 'pending' }, css: '', diag: null, note: '' };
		render();
		// One frame at a time: on a slow connection three copies of the page loading together (this window and
		// two frames) each take three times as long.
		var order = ['mobile', 'desktop'];
		(function next(i) {
			if (i >= order.length) { complete(); return; }
			analyseInFrame(order[i], function (result) {
				state.results[order[i]] = result;
				render();
				next(i + 1);
			});
		})(0);
	}

	/**
	 * Fallback without frames: analyse THIS window (its width decides mobile or desktop). Pressing it again at the
	 * other width (resize the window, or DevTools device mode) adds the other size; the results are merged.
	 */
	function analyseHere() {
		var device = deviceOfWidth(window.innerWidth);
		var result;
		try { result = analyse(); } catch (e) { result = { failed: true, kind: 'error', message: String(e && e.message || e), waited: 0, info: {} }; }
		state.results[device] = result;
		state.css = mergeToCss([state.results.mobile, state.results.desktop]);
		state.note = result.failed ? '' : t('hereDone').replace('%s', t(device));
		render();
	}

	/** Both screen sizes are done: merge what worked and, if anything failed, find out why. */
	function complete() {
		var results = [state.results.mobile, state.results.desktop];
		state.css = mergeToCss(results);
		render();
		if (results.some(function (r) { return !r || r.failed; })) {
			diagnose(function (diag) { state.diag = diag; render(); });
		}
	}

	function bind() {
		var link = document.querySelector('#wp-admin-bar-bepluspb-ccss > a');
		if (!link) { return; }
		link.addEventListener('click', function (event) {
			event.preventDefault();
			whenLoaded(start);
		});
	}

	document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && host) { closePanel(); } });
	bind();
})();
