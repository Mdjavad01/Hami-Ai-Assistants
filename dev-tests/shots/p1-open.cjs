/**
 * Bench tool: the OPEN state of every panel that carries `hidden`.
 *
 *   node dev-tests/shots/p1-open.cjs [out.png]
 *
 * The `[hidden]` guard must not break the reveal: after a real interaction the
 * element has to be back on screen. Also checks the front-end widget, which
 * lives outside `.haai-wrap` (`.haai-fab` → `.haai-float`).
 */
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';
const out = process.argv[2] || 'p1-open.png';
let fails = 0;

const ok = (label, cond, extra) => {
	if (!cond) fails++;
	console.log((cond ? '  ok   ' : '  FAIL ') + label.padEnd(46) + (extra || ''));
};

(async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, locale: 'fa-IR' });
	const page = await ctx.newPage();

	await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', 'haai_probe');
	await page.fill('#user_pass', 'Probe-2026-haai');
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/, { timeout: 20000 });

	/* ---- product-content: filter panel, selection bar, preview tabs ---- */
	await page.goto(base + '/wp-admin/admin.php?page=haai-product-content', { waitUntil: 'load' });
	await page.waitForSelector('.haai-prodtable tbody tr', { timeout: 20000 });
	await page.waitForTimeout(1000);

	const state = (sel) => page.evaluate((s) => {
		const el = document.querySelector(s);
		if (!el) return { missing: true };
		const cs = getComputedStyle(el);
		const box = el.getBoundingClientRect();
		return { hidden: el.hasAttribute('hidden'), display: cs.display, rendered: box.width > 0 && box.height > 0 };
	}, sel);

	ok('filter panel starts hidden', (await state('[data-haai-dd-panel]')).display === 'none');
	await page.click('[data-haai-dd-btn]');
	await page.waitForTimeout(250);
	let s = await state('[data-haai-dd-panel]');
	ok('filter panel opens on click', !s.hidden && s.display !== 'none' && s.rendered, 'display=' + s.display);
	await page.screenshot({ path: out.replace(/\.png$/, '-filter.png'), fullPage: false });
	await page.keyboard.press('Escape');
	await page.waitForTimeout(150);
	ok('Escape closes it again', (await state('[data-haai-dd-panel]')).display === 'none');

	ok('selection bar starts hidden', (await state('.haai-selectionbar')).display === 'none');
	await page.click('.haai-prodtable tbody tr:first-child .haai-row-check');
	await page.waitForTimeout(250);
	s = await state('.haai-selectionbar');
	ok('selection bar appears with a selection', !s.hidden && s.display !== 'none' && s.rendered, 'display=' + s.display);
	await page.click('.haai-prodtable tbody tr:first-child .haai-row-check');
	await page.waitForTimeout(200);
	ok('and hides again when cleared', (await state('.haai-selectionbar')).display === 'none');

	const pv = '[data-haai-pvedit]';
	if (await page.$(pv)) {
		ok('view pane is the visible one', (await state(pv + ' [data-haai-pane="view"]')).display !== 'none');
		await page.click(pv + ' [data-haai-tab="src"]');
		await page.waitForTimeout(200);
		s = await state(pv + ' [data-haai-pane="src"]');
		ok('HTML tab reveals the source pane', !s.hidden && s.display !== 'none' && s.rendered, 'display=' + s.display);
		ok('and hides the view pane', (await state(pv + ' [data-haai-pane="view"]')).display === 'none');
		await page.click(pv + ' [data-haai-tab="view"]');
		await page.waitForTimeout(200);
		ok('switching back restores the view', (await state(pv + ' [data-haai-pane="view"]')).display !== 'none');
	} else {
		ok('preview editor present (needs a stored preview)', false, 'skipped');
	}

	/* ---- semantic-search: the results list (rendered by [haai_search]) ---- */
	await page.goto(base + '/haai-bench-search-page/', { waitUntil: 'load' });
	await page.waitForTimeout(600);
	const box = await page.$('.haai-search__input');
	if (box) {
		ok('search results start hidden', (await state('.haai-search__results')).display === 'none');
		await box.fill('کفش');
		await page.waitForTimeout(2000);
		s = await state('.haai-search__results');
		// The guard's job is only "removing hidden shows it". Whether anything
		// removes it is the shortcode's business (reported as a finding).
		console.log((s.display === 'none' ? '  info ' : '  ok   ') + 'typing alone reveals the list'.padEnd(46) + 'display=' + s.display);
		await page.evaluate(() => document.querySelector('.haai-search__results').removeAttribute('hidden'));
		await page.waitForTimeout(150);
		s = await state('.haai-search__results');
		ok('removing hidden reveals the results list', !s.hidden && s.display !== 'none', 'display=' + s.display);
	} else {
		ok('search box found', false, 'skipped');
	}

	/* ---- front-end widget (outside .haai-wrap) ---- */
	await page.goto(base + '/', { waitUntil: 'load' });
	await page.waitForTimeout(800);
	s = await state('.haai-fab');
	ok('fab is visible on the front end', !s.missing && !s.hidden && s.rendered, 'display=' + s.display);
	ok('panel is absent before the first click', (await state('.haai-float')).missing === true);
	await page.click('.haai-fab');
	await page.waitForTimeout(2000);
	s = await state('.haai-float');
	ok('clicking the fab opens the panel', !s.missing && !s.hidden && s.display !== 'none' && s.rendered, 'display=' + s.display);
	await page.screenshot({ path: out.replace(/\.png$/, '-widget.png'), fullPage: false });

	console.log('\nOPEN-STATE FAILURES: ' + fails);
	await browser.close();
	process.exit(fails ? 1 : 0);
})();
