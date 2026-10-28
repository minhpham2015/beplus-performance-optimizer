/**
 * Confirmation + double-submit guard for the purge forms in the admin bar.
 *
 * Loaded on the front end and on admin screens other than the plugin settings page; there
 * admin.js binds every form.bepluspb-purge-form (including the admin bar ones) itself.
 */
(function () {
	'use strict';
	document.querySelectorAll('#wpadminbar form.bepluspb-purge-form').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			var message = form.getAttribute('data-confirm');
			if (message && !window.confirm(message)) { event.preventDefault(); return; }
			var button = form.querySelector('button[type="submit"]');
			if (button && button.disabled) { event.preventDefault(); return; }
			if (button) { button.disabled = true; button.setAttribute('aria-disabled', 'true'); }
		});
	});
})();
