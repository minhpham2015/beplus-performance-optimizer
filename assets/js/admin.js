/**
 * BePlus Optimizer — Admin settings page: tab navigation + cache toggle AJAX.
 *
 * Extracted from the inline <script> block in class-sob-admin.php (CQ-3)
 * so it can be cached by the browser, linted, and tested independently.
 *
 * @package Site_Optimizer_BePlus
 */
(function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Tab navigation
	// -------------------------------------------------------------------------

	var btns    = document.querySelectorAll('.sob-tab-btn');
	var panels  = document.querySelectorAll('.sob-tab-panel');
	var saveBar = document.getElementById('sob-save-bar');

	// Tabs that have their own forms and must NOT show the main Save bar.
	var noSaveBarTabs = { dashboard: true, status: true, ai_optimizer: true };

	/**
	 * Activate the tab with the given id, hide all others.
	 *
	 * @param {string} id Tab identifier (matches data-tab attribute).
	 */
	function activate(id) {
		btns.forEach(function (b) {
			var active = b.dataset.tab === id;
			b.classList.toggle('active', active);
			b.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		panels.forEach(function (p) {
			p.classList.toggle('active', p.id === 'sob-tab-' + id);
		});

		// Hide Save Settings bar on tabs that manage their own state.
		if (saveBar) {
			saveBar.style.display = noSaveBarTabs[id] ? 'none' : '';
		}

		try {
			sessionStorage.setItem('sob_active_tab', id);
		} catch (e) { /* storage may be unavailable (private browsing) */ }
	}

	// Bind click handlers.
	btns.forEach(function (btn) {
		btn.addEventListener('click', function () {
			activate(btn.dataset.tab);
		});
	});

	// Restore the last-active tab from sessionStorage, defaulting to the
	// first tab (Dashboard) when nothing is stored or the stored value no
	// longer refers to an existing panel.
	var saved;
	try { saved = sessionStorage.getItem('sob_active_tab'); } catch (e) {}

	if (saved && document.getElementById('sob-tab-' + saved)) {
		activate(saved);
	} else if (btns.length) {
		activate(btns[0].dataset.tab);
	}

	// -------------------------------------------------------------------------
	// Master cache toggle — AJAX save
	// -------------------------------------------------------------------------

	var toggle  = document.getElementById('sob-cache-enabled-toggle');
	var status  = document.getElementById('sob-toggle-status');
	var notice  = document.getElementById('sob-cache-disabled-notice');
	var clearBtn = document.getElementById('sob-clear-cache-btn');

	if (toggle && typeof sobAdmin !== 'undefined') {
		toggle.addEventListener('change', function () {
			var enabled = toggle.checked ? 1 : 0;

			// Optimistic UI update.
			updateToggleUI(enabled);

			// Send to server.
			var data = new FormData();
			data.append('action',  'sob_toggle_cache');
			data.append('nonce',   sobAdmin.toggleNonce);
			data.append('enabled', enabled);

			fetch(sobAdmin.ajaxUrl, { method: 'POST', body: data })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (!json.success) {
						// Revert on failure.
						updateToggleUI(enabled ? 0 : 1);
						toggle.checked = !toggle.checked;
					}
				})
				.catch(function () {
					// Revert on network error.
					updateToggleUI(enabled ? 0 : 1);
					toggle.checked = !toggle.checked;
				});
		});
	}

	/**
	 * Update the toggle label, warning notice, and clear button to reflect state.
	 *
	 * @param {number} enabled 1 = on, 0 = off.
	 */
	function updateToggleUI(enabled) {
		if (status) {
			if (enabled) {
				status.textContent = 'Enabled';
				status.className = 'sob-toggle-status sob-toggle-status--on';
			} else {
				status.textContent = 'Disabled';
				status.className = 'sob-toggle-status sob-toggle-status--off';
			}
		}

		if (notice) {
			if (enabled) {
				notice.style.display = 'none';
				notice.innerHTML = '';
			} else {
				notice.style.display = '';
				notice.innerHTML = '<p>&#9888; All performance optimizations are currently disabled. Your site is running without any caching, minification, lazy loading, or cleanup features.</p>';
			}
		}

		if (clearBtn) {
			if (enabled) {
				clearBtn.removeAttribute('aria-disabled');
				clearBtn.removeAttribute('tabindex');
				clearBtn.classList.remove('sob-clear-btn--empty');
			} else {
				clearBtn.setAttribute('aria-disabled', 'true');
				clearBtn.setAttribute('tabindex', '-1');
				clearBtn.classList.add('sob-clear-btn--empty');
			}
		}
	}

	// -------------------------------------------------------------------------
	// "Copy System Info" button — Status tab
	// -------------------------------------------------------------------------

	var copyBtn = document.getElementById('sob-copy-sysinfo');
	if (copyBtn) {
		var origLabel = copyBtn.innerHTML;

		copyBtn.addEventListener('click', function () {
			var sysInfo = document.getElementById('sob-sysinfo-text');
			if (!sysInfo) { return; }

			var text = sysInfo.textContent;

			function showCopied(success) {
				copyBtn.textContent = success ? '✅ Copied!' : '❌ Failed';
				if (success) { copyBtn.classList.add('sob-copied'); }
				setTimeout(function () {
					copyBtn.innerHTML = origLabel;
					copyBtn.classList.remove('sob-copied');
				}, 2000);
			}

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text)
					.then(function () { showCopied(true); })
					.catch(function () { showCopied(false); });
			} else {
				// Fallback for older browsers.
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
				document.body.appendChild(ta);
				ta.focus();
				ta.select();
				try {
					document.execCommand('copy');
					showCopied(true);
				} catch (e) {
					showCopied(false);
				}
				document.body.removeChild(ta);
			}
		});
	}

})();
