/**
 * Bench tool: real-browser check of the product-content screen.
 *
 *   node dev-tests/shots/p1-shot.cjs <out.png> [extra-url]
 *
 * 1. logs in with the temporary bench admin (haai_probe),
 * 2. reports the computed display of the elements that carry `hidden`,
 * 3. sweeps every .haai-* element whose display is flex/grid: sets `hidden`,
 *    re-reads the computed display and reports any element that stays visible
 *    (the `[hidden]` guard must win everywhere),
 * 4. saves a full-page screenshot.
 *
 * The temp admin is created by the bench setup and deleted at the end of the
 * session; it is never part of the plugin.
 */
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';
const out = process.argv[2] || 'p1.png';
const extra = process.argv[3] || '';

(async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, locale: 'fa-IR' });
	const page = await ctx.newPage();

	await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', 'haai_probe');
	await page.fill('#user_pass', 'Probe-2026-haai');
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/, { timeout: 20000 });

	await page.goto(base + '/wp-admin/admin.php?page=haai-product-content', { waitUntil: 'load' });
	await page.waitForSelector('.haai-prodtable tbody tr', { timeout: 20000 });
	await page.waitForTimeout(1200);

	const probe = await page.evaluate(() => {
		const line = (s) => {
			const el = document.querySelector(s);
			if (!el) return s + ' → MISSING';
			const cs = getComputedStyle(el);
			const box = el.getBoundingClientRect();
			return s + ' → hidden=' + el.hasAttribute('hidden') + ' display=' + cs.display +
				' rendered=' + (box.width > 0 && box.height > 0);
		};
		return [
			line('[data-haai-dd-panel]'),
			line('[data-haai-dd-count]'),
			line('.haai-pager'),
			line('.haai-selectionbar'),
			line('[data-haai-pvedit] [data-haai-pane="src"]'),
		].join('\n');
	});
	console.log(probe);

	// Sweep: does `hidden` win for every flex/grid element on the page?
	const sweep = await page.evaluate(() => {
		const bad = [];
		let checked = 0;
		document.querySelectorAll('.haai-wrap [class*="haai-"]').forEach((el) => {
			const d = getComputedStyle(el).display;
			if (d !== 'flex' && d !== 'grid' && d !== 'inline-flex' && d !== 'inline-grid') return;
			checked++;
			const had = el.hasAttribute('hidden');
			el.setAttribute('hidden', '');
			const after = getComputedStyle(el).display;
			if (after !== 'none') bad.push(el.className + ' → ' + after);
			if (!had) el.removeAttribute('hidden');
		});
		return 'checked=' + checked + ' still-visible=' + bad.length + (bad.length ? '\n  ' + bad.join('\n  ') : '');
	});
	console.log('sweep: ' + sweep);

	await page.screenshot({ path: out, fullPage: true });
	console.log('saved: ' + out);

	if (extra) {
		await page.goto(extra, { waitUntil: 'load' });
		await page.waitForTimeout(800);
		const sweep2 = await page.evaluate(() => {
			const bad = [];
			let checked = 0;
			document.querySelectorAll('.haai-wrap [class*="haai-"]').forEach((el) => {
				const d = getComputedStyle(el).display;
				if (d !== 'flex' && d !== 'grid' && d !== 'inline-flex' && d !== 'inline-grid') return;
				checked++;
				const had = el.hasAttribute('hidden');
				el.setAttribute('hidden', '');
				const after = getComputedStyle(el).display;
				if (after !== 'none') bad.push(el.className + ' → ' + after);
				if (!had) el.removeAttribute('hidden');
			});
			return 'checked=' + checked + ' still-visible=' + bad.length + (bad.length ? '\n  ' + bad.join('\n  ') : '');
		});
		console.log('sweep(extra): ' + sweep2);
	}

	await browser.close();
})();
