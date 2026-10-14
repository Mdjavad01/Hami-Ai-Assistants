/**
 * Diagnostic probe (Batch C2 / P8): after a debounced re-render, does the row
 * checkbox still exist, and what has focus? Temporary diagnostic; dev-tests only.
 */
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';

(async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1500, height: 1100 }, locale: 'fa-IR' });
	const page = await ctx.newPage();
	page.on('pageerror', (e) => console.log('  !! pageerror: ' + e.message));

	await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', 'haai_probe');
	await page.fill('#user_pass', 'Probe-2026-haai');
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/, { timeout: 20000 });

	await page.goto(base + '/wp-admin/admin.php?page=haai-product-content', { waitUntil: 'load' });
	await page.waitForSelector('.haai-prodtable tbody tr .haai-row-check', { timeout: 20000 });
	await page.waitForTimeout(1200);

	const start = await page.evaluate(() => {
		const box = document.querySelector('.haai-prodtable tbody .haai-row-check');
		box.focus();
		const tbody = document.querySelector('[data-haai-pbody]');
		return {
			id: box.value,
			activeIsBox: document.activeElement === box,
			tbodyTag: tbody ? tbody.tagName : null,
			contained: tbody ? tbody.contains(box) : null,
		};
	});
	console.log('start: ' + JSON.stringify(start));

	await page.evaluate(() => {
		const q = document.querySelector('[data-haai-pq]');
		q.value = 'کفش';
		q.dispatchEvent(new Event('input', { bubbles: true }));
	});
	await page.waitForTimeout(2200);

	const end = await page.evaluate((id) => {
		const a = document.activeElement;
		return {
			stillThere: !!document.querySelector('.haai-row-check[value="' + id + '"]'),
			total: document.querySelectorAll('.haai-row-check').length,
			activeTag: a ? a.tagName : null,
			activeClass: a ? String(a.className) : null,
			activeIsRowBox: !!(a && a.classList && a.classList.contains('haai-row-check')),
		};
	}, start.id);
	console.log('end: ' + JSON.stringify(end));

	await browser.close();
})();
