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

	/* A page cache or a JS optimiser (delay / combine / defer) can drop the inline
	 * HAAI_CONFIG script while leaving the button in the cached HTML. Rebuild the
	 * few values the launcher needs from the button's own data-* attributes —
	 * otherwise the click handler used to return silently and the visitor got no
	 * reaction at all. */
	if (!c || !c.widgetUrl) {
		var seed = document.querySelector('.haai-fab');
		var d = (seed && seed.dataset) || {};
		if (d.haaiWidget) {
			c = {
				rest: d.haaiRest || '',
				tokenUrl: d.haaiToken || '',
				widgetUrl: d.haaiWidget,
				css: d.haaiCss || '',
				js: d.haaiJs || '',
				fab: { side: d.haaiSide || 'right', teaser: d.haaiTeaser || '' }
			};
		}
	}
	if (!c || !c.widgetUrl) return;

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
		if (!c.css) return;
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

		/* chatbot.js reads window.HAAI_CONFIG itself. When the inline script was
		 * stripped we only rebuilt the launcher's half of it; the REST response now
		 * carries the full config, so merge it in BEFORE the script runs. Without
		 * this the panel opened and every send/token call hit `undefined`. */
		var server = d.config || null;
		if (server) {
			var merged = {};
			var k;
			for (k in c) { if (Object.prototype.hasOwnProperty.call(c, k)) merged[k] = c[k]; }
			for (k in server) { if (Object.prototype.hasOwnProperty.call(server, k)) merged[k] = server[k]; }
			window.HAAI_CONFIG = merged;
		} else {
			window.HAAI_CONFIG = c;
		}

		var script = document.createElement('script');
		script.src = c.js;
		script.defer = true;
		if (c.js) document.body.appendChild(script);
	}

	// Everything the panel needs, exactly once. Resolves when it is in the DOM.
	function load() {
		if (ready) return ready;
		css();
		ready = fetch(c.widgetUrl + (c.widgetUrl.indexOf('?') === -1 ? '?' : '&') + 'url=' + encodeURIComponent(location.href), { credentials: 'same-origin' })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(build)
			.catch(function (err) {
				ready = null;
				// Silence here was the user's complaint: the button looked alive and
				// did nothing. Say it out loud and let the next click retry.
				toast('ارتباط با دستیار برقرار نشد. یک‌بار دیگر تلاش کنید.');
				if (window.console && console.warn) console.warn('[HAAI] widget failed:', err);
			});
		return ready;
	}

	/** One short, self-dismissing bubble above the button (reuses .haai-teaser). */
	function toast(text) {
		if (teaser) return;
		teaser = document.createElement('button');
		teaser.type = 'button';
		teaser.className = 'haai-teaser is-in' + (c.fab && c.fab.side === 'left' ? ' is-left' : '');
		teaser.setAttribute('dir', 'rtl');
		teaser.textContent = text;
		teaser.addEventListener('click', function () { hideTeaser(false); });
		(document.body || document.documentElement).appendChild(teaser);
		teaserTimer = setTimeout(function () { hideTeaser(false); }, 8000);
	}

	function open() {
		hideTeaser(true);
		panel.removeAttribute('hidden');
		fab.setAttribute('aria-expanded', 'true');
		fab.classList.add('is-open'); // off while the panel covers it (sheet/glass).
		if (window.HAAI_CHAT) window.HAAI_CHAT.focus();
	}

	function close() {
		panel.setAttribute('hidden', '');
		fab.setAttribute('aria-expanded', 'false');
		fab.classList.remove('is-open');
	}

	fab.addEventListener('click', function () {
		load().then(function () {
			if (!panel) return; // fetch failed; nothing to open yet.
			if (panel.hasAttribute('hidden')) open(); else close();
		});
	});

	document.addEventListener('haai:close', function () {
		fab.setAttribute('aria-expanded', 'false');
		fab.classList.remove('is-open');
	});

	// Escape closes the panel while it is the thing on screen.
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key && panel && !panel.hasAttribute('hidden')) close();
	});

	/* Teaser bubble — one gentle nudge per session, a few seconds after load,
	 * until the visitor opens the chat once. Markup lives here; its geometry
	 * ships in the launcher's critical CSS, its visual style in chatbot.css. */
	var TEASER_KEY = 'haai_teaser_v1';
	var teaser = null;
	var teaserTimer = null;

	function sessionSeen() {
		try { return !!window.sessionStorage.getItem(TEASER_KEY); } catch (e) { return true; }
	}
	function sessionMark() {
		try { window.sessionStorage.setItem(TEASER_KEY, '1'); } catch (e) { /* private mode */ }
	}

	function hideTeaser(persist) {
		if (teaserTimer) { clearTimeout(teaserTimer); teaserTimer = null; }
		if (teaser) {
			var t = teaser;
			teaser = null;
			t.classList.remove('is-in');
			setTimeout(function () { t.remove(); }, 350);
		}
		if (persist) sessionMark();
	}

	function showTeaser() {
		if (sessionSeen() || !c.fab || !c.fab.teaser) return;
		teaser = document.createElement('button');
		teaser.type = 'button';
		teaser.className = 'haai-teaser' + (c.fab.side === 'left' ? ' is-left' : '');
		teaser.setAttribute('dir', 'rtl');
		teaser.textContent = c.fab.teaser;
		teaser.addEventListener('click', function () {
			hideTeaser(true);
			if (panel && panel.hasAttribute('hidden')) open();
		});
		document.body.appendChild(teaser);
		(requestAnimationFrame || setTimeout)(function () {
			if (teaser) teaser.classList.add('is-in');
		});
		sessionMark(); // shown once - never nag on every page view.
		teaserTimer = setTimeout(function () { hideTeaser(false); }, 12000);
	}

	('requestIdleCallback' in window ? requestIdleCallback : function (fn) { setTimeout(fn, 1500); })(css);
	['pointerenter', 'pointerdown', 'touchstart', 'focus'].forEach(function (ev) {
		fab.addEventListener(ev, load);
	});

	setTimeout(showTeaser, 4000);
})();
