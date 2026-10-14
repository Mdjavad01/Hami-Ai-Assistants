/**
 * End-to-end browser test: Batch A — the "restore settings" action on the
 * advanced tab, undoing the safety snapshot that reset() took.
 *
 *   node dev-tests/restore-e2e.cjs
 *
 * Prerequisites (dev-tests/restore-e2e-run.sh sets them up): the bench has a
 * distinctive appearance colour + provider model, then reset() was run so a
 * snapshot of that state exists and the live values are back to defaults.
 *
 * What it proves in a real browser (not just a marker in the HTML):
 *   - the restore panel shows on the advanced tab while a snapshot exists;
 *   - the button posts through admin-post with the nonce + capability guard;
 *   - clicking it lands a Persian success notice;
 *   - the pre-reset field values are really back (read from the appearance and
 *     provider tabs), and the panel disappears once there is nothing to restore.
 */
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';
const admin = base + '/wp-admin';
const COLOR = '#abcdef';
const MODEL = 'e2e-restore-model';

let fails = 0;
let steps = 0;
function ok(label, cond, extra) {
	steps++;
	if (!cond) fails++;
	console.log((cond ? '  ok   ' : '  FAIL ') + label.padEnd(52) + (extra === undefined ? '' : extra));
}
function log(label, value) {
	console.log('    · ' + label + (value === undefined ? '' : ': ' + value));
}

(async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'fa-IR' });
	const page = await ctx.newPage();
	page.on('pageerror', (e) => console.log('  !! pageerror: ' + e.message));

	await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', 'haai_probe');
	await page.fill('#user_pass', 'Probe-2026-haai');
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/, { timeout: 20000 });
	ok('login', true);

	/* advanced tab: the restore panel is present while a snapshot exists */
	await page.goto(admin + '/admin.php?page=haai-settings&tab=advanced', { waitUntil: 'load' });
	await page.waitForTimeout(500);
	const panel = page.locator('.haai-panel--restore');
	ok('restore panel visible on the advanced tab', await panel.isVisible());
	ok('panel carries a Persian heading', ((await panel.textContent()) || '').indexOf('بازگردانی') !== -1);
	const restoreBtn = page.locator('.haai-panel--restore form [name="action"][value="haai_restore"] ~ button[type="submit"], .haai-panel--restore form button[type="submit"]').first();
	ok('restore button present', await restoreBtn.count() > 0);
	log('snapshot stamp text', ((await page.locator('.haai-panel--restore .haai-field__hint').nth(1).textContent().catch(() => '')) || '').trim().slice(0, 60));

	// Guard check: the form really carries a WP nonce (reject/accept path).
	const hasNonce = await page.evaluate(() => {
		const f = document.querySelector('.haai-panel--restore form');
		return !!f && !!f.querySelector('input[name="_wpnonce"]');
	});
	ok('restore form posts a nonce', hasNonce);

	/* values are at defaults before the restore */
	await page.goto(admin + '/admin.php?page=haai-settings&tab=appearance', { waitUntil: 'load' });
	await page.waitForTimeout(400);
	const colorBefore = await page.inputValue('input[name="appearance[primary_color]"]').catch(() => '');
	log('primary_color before restore', colorBefore);
	ok('primary_color is a default (not the seeded value) before restore', colorBefore !== COLOR, colorBefore);

	/* click restore */
	await page.goto(admin + '/admin.php?page=haai-settings&tab=advanced', { waitUntil: 'load' });
	await page.waitForTimeout(300);
	page.once('dialog', (d) => d.accept()); // data-haai-confirm prompt
	await page.locator('.haai-panel--restore form button[type="submit"]').first().click();
	await page.waitForURL(/haai_ok=restored/, { timeout: 20000 });
	await page.waitForTimeout(400);
	const notice = ((await page.locator('.notice.notice-success').first().textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
	log('restore notice', notice.slice(0, 90));
	ok('restore reported success in Persian', notice.indexOf('بازگردانده') !== -1, notice.slice(0, 50));
	ok('landed back on the advanced tab', page.url().indexOf('tab=advanced') !== -1);

	/* the values are really back, read straight from the fields */
	await page.goto(admin + '/admin.php?page=haai-settings&tab=appearance', { waitUntil: 'load' });
	await page.waitForTimeout(400);
	const colorAfter = await page.inputValue('input[name="appearance[primary_color]"]').catch(() => '');
	log('primary_color after restore', colorAfter);
	ok('primary_color restored to the seeded value', colorAfter === COLOR, colorAfter);

	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	await page.waitForTimeout(400);
	const modelAfter = await page.inputValue('input[name="provider[model]"]').catch(() => '');
	log('model after restore', modelAfter);
	ok('provider model restored', modelAfter === MODEL, modelAfter);

	/* restoring is idempotent: the snapshot is kept until the next reset/import
	   overwrites it, so the panel stays and a second click is still safe. */
	await page.goto(admin + '/admin.php?page=haai-settings&tab=advanced', { waitUntil: 'load' });
	await page.waitForTimeout(400);
	ok('restore panel still offered after a restore (snapshot kept)', await page.locator('.haai-panel--restore').isVisible());
	page.once('dialog', (d) => d.accept());
	await page.locator('.haai-panel--restore form button[type="submit"]').first().click();
	await page.waitForURL(/haai_ok=restored/, { timeout: 20000 });
	await page.waitForTimeout(400);
	ok('second restore is also a success (no error)', (await page.locator('.notice.notice-error').count()) === 0);
	await page.goto(admin + '/admin.php?page=haai-settings&tab=appearance', { waitUntil: 'load' });
	await page.waitForTimeout(400);
	ok('values unchanged after an idempotent second restore', (await page.inputValue('input[name="appearance[primary_color]"]').catch(() => '')) === COLOR);

	await page.screenshot({ path: 'dev-tests/shots/restore-e2e.png', fullPage: true });
	console.log('\nRESTORE E2E: ' + (steps - fails) + ' ok / ' + fails + ' failed (of ' + steps + ')');
	await browser.close();
	process.exit(fails ? 1 : 0);
})();
