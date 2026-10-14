/**
 * Hami AI Assistant — floating launcher wiring.
 *
 * The button and its critical CSS come from the plugin, so it is placed before
 * this file runs. The real stylesheet loads at idle and the widget on the first
 * hover / touch / focus, so a click usually finds work already started — and a
 * page nobody interacts with downloads nothing beyond this file.
 */
(function () {
	'use strict';

	var c = window.HAAI_CONFIG;
	if (!c) return;

	var fab = document.querySelector('.haai-fab');
	if (!fab) {
		// Only reached from HTML cached before the button was printed.
		fab = document.createElement('button');
		fab.type = 'button';
		fab.className = 'haai-fab';
		fab.innerHTML = c.fab.icon || '';
		(document.body || document.documentElement).appendChild(fab);
	}
	if (!fab.getAttribute('aria-label')) fab.setAttribute('aria-label', 'گفتگو با پشتیبانی');
	fab.setAttribute('aria-expanded', 'false');

	var panel = null;
	var ready = null;
	var styled = false;

	function css() {
		if (styled) return;
		styled = true;
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = c.css;
		document.head.appendChild(link);
	}

	function build(d) {
		var style = document.createElement('style');
		style.textContent = d.css || '';
		document.head.appendChild(style);

		panel = document.createElement('div');
		panel.className = 'haai-float' + (c.fab.side === 'left' ? ' is-left' : '');
		panel.innerHTML = d.html || '';
		panel.setAttribute('hidden', '');
		document.body.appendChild(panel);

		var script = document.createElement('script');
		script.src = c.js;
		script.defer = true;
		document.body.appendChild(script);
	}

	// Everything the panel needs, exactly once. Resolves when it is in the DOM.
	function load() {
		if (ready) return ready;
		css();
		ready = fetch(c.widgetUrl + (c.widgetUrl.indexOf('?') === -1 ? '?' : '&') + 'url=' + encodeURIComponent(location.href), { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(build)
			.catch(function () { ready = null; });
		return ready;
	}

	function open() {
		panel.removeAttribute('hidden');
		fab.setAttribute('aria-expanded', 'true');
		if (window.HAAI_CHAT) window.HAAI_CHAT.focus();
	}

	function close() {
		panel.setAttribute('hidden', '');
		fab.setAttribute('aria-expanded', 'false');
	}

	fab.addEventListener('click', function () {
		load().then(function () {
			if (!panel) return; // fetch failed; nothing to open yet.
			if (panel.hasAttribute('hidden')) open(); else close();
		});
	});

	document.addEventListener('haai:close', function () {
		fab.setAttribute('aria-expanded', 'false');
	});

	('requestIdleCallback' in window ? requestIdleCallback : function (fn) { setTimeout(fn, 1500); })(css);
	['pointerenter', 'pointerdown', 'touchstart', 'focus'].forEach(function (ev) {
		fab.addEventListener(ev, load);
	});
})();
