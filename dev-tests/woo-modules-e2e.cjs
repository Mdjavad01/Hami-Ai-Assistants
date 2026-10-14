/**
 * Browser test for Batch B (M3): with WooCommerce off, the Modules screen
 * shows the "نیازمند ووکامرس" badge + a disabled switch for every
 * WooCommerce-only module, and saving the OTHER toggles must NOT clear their
 * stored "on" flag.
 *
 *   node dev-tests/woo-modules-e2e.cjs
 *
 * Prerequisites (dev-tests/woo-modules-e2e-run.sh): WooCommerce deactivated,
 * every module switched on. Restores the bench afterwards.
 */
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';
const admin = base + '/wp-admin';
const mods = admin + '/admin.php?page=haai-modules';

let fails = 0;
let steps = 0;
function ok(label, cond, extra) {
	steps++;
	if (!cond) fails++;
	console.log((cond ? '  ok   ' : '  FAIL ') + label.padEnd(56) + (extra === undefined ? '' : extra));
}

/** Read one module card's checkbox state + whether it is blocked. */
async function cardState(page, slug) {
	return page.evaluate((s) => {
		const sel = 'input[name="modules[' + s + ']"]';
		const box = document.querySelector(sel);
		if (!box) return null;
		const card = box.closest('.haai-mod-card');
		const warn = card ? card.querySelector('.haai-badge--warn') : null;
		return {
			checked: box.checked,
			disabled: box.disabled,
			badge: warn ? (warn.textContent || '').trim() : '',
		};
	}, slug);
}

(async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 1100 }, locale: 'fa-IR' });
	const page = await ctx.newPage();
	page.on('pageerror', (e) => console.log('  !! pageerror: ' + e.message));

	await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', 'haai_probe');
	await page.fill('#user_pass', 'Probe-2026-haai');
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/, { timeout: 20000 });
	ok('login', true);

	await page.goto(mods, { waitUntil: 'load' });
	await page.waitForSelector('.haai-mod-card', { timeout: 20000 });

	/* WooCommerce-only modules: blocked badge + disabled switch, but still "on" */
	const pc = await cardState(page, 'product-content');
	ok('product-content card present', pc !== null);
	ok('product-content switch is disabled', pc && pc.disabled === true, JSON.stringify(pc));
	ok('product-content badge says "نیازمند ووکامرس"', pc && pc.badge.indexOf('نیازمند ووکامرس') !== -1, pc && pc.badge);
	ok('product-content is still rendered as ON (checked)', pc && pc.checked === true);

	const disc = await cardState(page, 'discount');
	ok('discount switch is disabled', disc && disc.disabled === true);
	ok('discount badge is the WooCommerce one', disc && disc.badge.indexOf('نیازمند ووکامرس') !== -1);
	ok('discount is still checked (stored on kept)', disc && disc.checked === true);

	/* A non-woocommerce module is fully interactive */
	const aw = await cardState(page, 'article-writer');
	ok('article-writer switch is enabled', aw && aw.disabled === false);
	ok('article-writer has no WooCommerce badge', aw && aw.badge === '');

	/* Toggle article-writer OFF and save; the blocked modules must survive.
	   The custom switch paints a track over the input, so set the real
	   checkbox state directly (that is exactly what the form posts). */
	await page.evaluate(() => {
		const b = document.querySelector('input[name="modules[article-writer]"]');
		b.checked = false;
		b.dispatchEvent(new Event('change', { bubbles: true }));
	});
	await page.click('button[type="submit"]');
	await page.waitForURL(/haai_ok=module-saved/, { timeout: 20000 });
	await page.waitForTimeout(400);
	ok('save redirected with module-saved', page.url().indexOf('module-saved') !== -1);
	ok('no error notice after save', (await page.locator('.notice-error').count()) === 0);

	/* Reload and read the stored state back through the same screen. */
	await page.goto(mods, { waitUntil: 'load' });
	await page.waitForSelector('.haai-mod-card', { timeout: 20000 });
	const aw2 = await cardState(page, 'article-writer');
	ok('article-writer is now OFF', aw2 && aw2.checked === false);
	const pc2 = await cardState(page, 'product-content');
	const disc2 = await cardState(page, 'discount');
	ok('product-content stored ON survived the save', pc2 && pc2.checked === true, JSON.stringify(pc2));
	ok('discount stored ON survived the save', disc2 && disc2.checked === true, JSON.stringify(disc2));

	await page.screenshot({ path: 'dev-tests/shots/woo-modules-off.png', fullPage: true });
	console.log('\nWOO MODULES E2E: ' + (steps - fails) + ' ok / ' + fails + ' failed (of ' + steps + ')');
	await browser.close();
	process.exit(fails ? 1 : 0);
})();
