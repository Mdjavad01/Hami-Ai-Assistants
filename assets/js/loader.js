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

	/* CSP: a site with a nonce-based script-src allows this file (WordPress prints
	 * it with the nonce) but BLOCKS anything injected later without one. That is
	 * the panel-opens-but-nothing-works case: markup and inline CSS arrive, the
	 * widget script is refused, and the browser only says so in the console. Take
	 * the nonce from our own tag and hand it to every element we create. */
	var NONCE = (document.currentScript && document.currentScript.nonce) || '';

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

	/**
	 * Load chatbot.css once. The URL can come from either half of the config:
	 * the button (data-haai-css) or the REST response. A button printed before
	 * 2.1.53 carries no data-* at all — marking `styled` before checking the URL
	 * then dropped the stylesheet for good, which is why the send arrow was an
	 * invisible icon on a red circle and the layout looked half-finished.
	 */
	function css(href) {
		if (styled) return;
		var url = href || c.css || '';
		if (!url) return;
		styled = true;
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = url;
		if (NONCE) link.nonce = NONCE;
		document.head.appendChild(link);
	}

	function build(d) {
		var style = document.createElement('style');
		if (NONCE) style.nonce = NONCE;
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

		/* Take the script URL from the MERGED config: a button whose HTML was
		 * cached before 2.1.53 carries no data-haai-js, so the local copy is empty
		 * and the old code injected nothing at all — panel open, every click dead. */
		var js = (window.HAAI_CONFIG && window.HAAI_CONFIG.js) || c.js || '';
		css( window.HAAI_CONFIG && window.HAAI_CONFIG.css );

		var script = document.createElement('script');
		script.src = js;
		script.defer = true;
		if (NONCE) script.nonce = NONCE;
		script.onerror = function () {
			// A stale .min.js (a partial FTP upload) or a WAF rule: try the source
			// file once before giving up — a working widget beats a tidy message.
			var plain = String(script.src || '').replace(/\.min\.js/, '.js');
			if (plain && plain !== script.src) {
				if (window.console && console.warn) console.warn('[HAAI] min asset failed, retrying the source file:', plain);
				var alt = document.createElement('script');
				alt.src = plain;
				alt.defer = true;
				if (NONCE) alt.nonce = NONCE;
				alt.onerror = function () {
					window.HAAI_CONFIG = window.HAAI_CONFIG || c;
					toast('فایل اسکریپت چت بارگذاری نشد (مسدود یا ۴۰۴). صفحه را بدون مسدودکننده تست کنید.');
					if (window.console && console.error) console.error('[HAAI] chatbot.js failed to load:', alt.src);
				};
				document.body.appendChild(alt);
				return;
			}
			window.HAAI_CONFIG = window.HAAI_CONFIG || c;
			toast('فایل اسکریپت چت بارگذاری نشد (مسدود یا ۴۰۴). صفحه را بدون مسدودکننده تست کنید.');
			if (window.console && console.error) console.error('[HAAI] chatbot.js failed to load:', script.src);
		};
		// A refused script fires `error`; one that runs but bails out fires `load`
		// and leaves HAAI_CHAT unset. Naming those two apart is the whole diagnosis.
		script.onload = function () {
			window.setTimeout(function () {
				if (window.HAAI_CHAT) return;
				if (window.console && console.error) console.error('[HAAI] chatbot.js ran but did not initialise (root missing or an exception above)', { src: js });
				toast('اسکریپت چت اجرا نشد — کنسول مرورگر خط [HAAI] را نشان می‌دهد.');
			}, 1200);
		};
		if (js) {
			document.body.appendChild(script);
		} else {
			// The server config and the button both lacked a script URL; without one
			// the widget can never answer a click.
			toast('آدرس فایل چت از سرور نیامد؛ صفحه را یک‌بار تازه کنید.');
			if (window.console && console.error) console.error('[HAAI] no chatbot script URL in config', window.HAAI_CONFIG);
		}

		// Watchdog: if chatbot.js loaded but never reached its own init, no handler
		// is bound and every click is silently ignored. Name the reason on screen.
		window.setTimeout(function () {
			if (window.HAAI_CHAT) return;
			if (window.console && console.error) {
				console.error('[HAAI] chatbot.js did not initialise', {
					src: js,
					hasConfig: !!window.HAAI_CONFIG,
					widget: !!document.querySelector('.haai-widget:not(.haai-widget--preview)')
				});
			}
			if (!document.querySelector('.haai-widget:not(.haai-widget--preview)')) {
				toast('قالب ویجت از سرور نیامد؛ صفحه را دوباره بارگذاری کنید.');
			} else {
				toast('چت آماده نشد: اسکریپت اجرا نشد یا خطا داد (کنسول را ببینید).');
			}
		}, 4000);
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
		// The engagement teaser may already own this bubble. Returning early (the
		// old behaviour) swallowed every error message in exactly the moment the
		// visitor needed it, so take the bubble over instead.
		if (teaser) {
			teaser.textContent = text;
			teaser.classList.add('is-in');
			if (teaserTimer) clearTimeout(teaserTimer);
			teaserTimer = setTimeout(function () { hideTeaser(false); }, 10000);
			return;
		}
		teaser = document.createElement('button');
		teaser.type = 'button';
		teaser.className = 'haai-teaser is-in' + (c.fab && c.fab.side === 'left' ? ' is-left' : '');
		teaser.setAttribute('dir', 'rtl');
		teaser.textContent = text;
		teaser.addEventListener('click', function () { hideTeaser(false); });
		(document.body || document.documentElement).appendChild(teaser);
		teaserTimer = setTimeout(function () { hideTeaser(false); }, 10000);
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
