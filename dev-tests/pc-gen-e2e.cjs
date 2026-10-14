/**
 * Browser test for Batch C1: P3 (one click = one run) and P4 («اعمال» is
 * idempotent), against the real plugin on haai-test with the mock LLM.
 *
 *   node dev-tests/pc-gen-e2e.cjs <productId>
 *
 * Prerequisites (dev-tests/pc-gen-e2e-run.sh): the provider points at the mock
 * with model «mock-model-slow» (6 s per call) so a click has visible duration,
 * allow_localhost = 1, retries = 0, and the product has no preview, no lock and
 * no apply marker.
 *
 * What is proven, by observation and not by markup:
 *   A. clicking generate immediately disables the button and re-labels it;
 *   B. two concurrent generate posts cost exactly ONE model call — the second
 *      is refused with the Persian busy message (server lock);
 *   C. applying twice answers the second time with a calm success message,
 *      never a red error.
 */
const fs = require('fs');
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';
const admin = base + '/wp-admin';
const LOG = __dirname + '/mock-api-log.jsonl';
const pid = process.argv[2] || '';

let fails = 0;
let steps = 0;
function ok(label, cond, extra) {
	steps++;
	if (!cond) fails++;
	console.log((cond ? '  ok   ' : '  FAIL ') + label.padEnd(56) + (extra === undefined ? '' : extra));
}
function log(label, value) {
	console.log('    · ' + label + (value === undefined ? '' : ': ' + value));
}
const callCount = () => (fs.existsSync(LOG) ? fs.readFileSync(LOG, 'utf8').split('\n').filter(Boolean).length : 0);

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

	const screen = admin + '/admin.php?page=haai-product-content&product=' + pid;
	await page.goto(screen, { waitUntil: 'load' });
	await page.waitForTimeout(600);
	const nonce = await page.inputValue('form input[name="_wpnonce"]');
	ok('screen carries a nonce', !!nonce);

	/* ---------- A: the click disables the button and re-labels it ----------
	 * A cancelling listener (registered after the plugin guard) stops the real
	 * navigation, so the mid-run DOM can be read. Without it, reading the page
	 * would block until the 6 s response arrived.                             */
	await page.evaluate((sel) => {
		const inp = document.querySelector(sel);
		const form = inp ? inp.form : null;
		if (!form) return;
		form.addEventListener('submit', function (e) { e.preventDefault(); }, false);
	}, 'input[name="action"][value="haai_pc_generate"]');

	const genForm = page.locator('form:has(input[name="action"][value="haai_pc_generate"])');
	await genForm.locator('input[name="keywords"]').fill('کفش چرم، خرید');
	await genForm.locator('button[type="submit"]').first().click();
	await page.waitForTimeout(400);

	const midState = await page.evaluate(() => {
		const inp = document.querySelector('input[name="action"][value="haai_pc_generate"]');
		const form = inp ? inp.form : null;
		const btn = form ? form.querySelector('button[type="submit"]') : null;
		return btn ? { disabled: btn.disabled, text: (btn.textContent || '').trim(), flag: form.getAttribute('data-haai-submitting') } : null;
	});
	log('button while running', JSON.stringify(midState));
	ok('generate button is disabled mid-run', midState && midState.disabled === true, JSON.stringify(midState));
	ok('generate button says it is working', midState && midState.text.indexOf('در حال ساخت') === 0, midState && midState.text);
	ok('the form is marked as submitting', midState && midState.flag === '1');

	/* ---------- B: one real click, one overlapping post ---------------------
	 * Fresh page (the cancelling listener is gone). The click starts a 6 s run;
	 * the same request is posted again while it is still in flight — what a
	 * second click, a second tab or a refresh does. The lock must refuse it
	 * BEFORE it reaches the model. Nothing is read from the page in between,
	 * because that would wait for the navigation and destroy the overlap.     */
	await page.goto(screen, { waitUntil: 'load' });
	await page.waitForTimeout(600);
	const before = callCount();
	const genForm2 = page.locator('form:has(input[name="action"][value="haai_pc_generate"])');
	await genForm2.locator('input[name="keywords"]').fill('کفش چرم، خرید');
	await genForm2.locator('button[type="submit"]').first().click({ noWaitAfter: true });
	await page.waitForTimeout(250);

	const dup = await page.request.post(admin + '/admin-post.php', {
		form: { _wpnonce: nonce, action: 'haai_pc_generate', product_id: pid, tone: 'friendly', length: 'long', keywords: 'کفش چرم' },
	});
	await page.waitForSelector('[data-haai-pvedit]', { timeout: 45000 });
	const calls = callCount() - before;
	log('model calls for click + overlapping post', calls);
	log('overlapping post landed on', dup.url().indexOf('haai_err') !== -1 ? 'a refusal notice' : 'a normal screen');
	ok('the overlapping post cost NO extra model call (one call total)', calls === 1, 'calls=' + calls);
	ok('the overlapping post was refused', dup.url().indexOf('haai_err') !== -1);

	// the refusal text is Persian and explains the wait
	await page.goto(dup.url(), { waitUntil: 'load' });
	await page.waitForTimeout(500);
	const busyText = ((await page.locator('.notice-error').first().textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
	log('refusal notice', busyText.slice(0, 110));
	ok('refusal notice is Persian about an ongoing run', busyText.indexOf('در حال انجام') !== -1, busyText.slice(0, 60));
	ok('refusal notice tells the user to retry', busyText.indexOf('دوباره بزنید') !== -1);
	ok('the finished run left a preview on screen', (await page.locator('[data-haai-pvedit]').count()) > 0);

	/* ---------- C. applying twice is calm, not red ------------------------ */

	const nonce2 = await page.inputValue('form input[name="_wpnonce"]');
	const first = await page.request.post(admin + '/admin-post.php', {
		// notes_seen: the short-text note the mock reply triggers is open, and
		// applying with an open note is refused by design (tested in pc-e2e).
		form: { _wpnonce: nonce2, action: 'haai_pc_apply', product_id: pid, notes_seen: '1' },
	});
	await page.goto(first.url(), { waitUntil: 'load' });
	await page.waitForTimeout(600);
	const firstNotice = ((await page.locator('.notice').first().textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
	log('first apply notice', firstNotice.slice(0, 90));
	ok('first apply reports success', first.url().indexOf('haai_msg') !== -1 && first.url().indexOf('haai_err') === -1, first.url().slice(-40));

	// second apply: the preview is gone, the marker says it just happened
	const second = await page.request.post(admin + '/admin-post.php', {
		form: { _wpnonce: nonce2, action: 'haai_pc_apply', product_id: pid },
	});
	await page.goto(second.url(), { waitUntil: 'load' });
	await page.waitForTimeout(600);
	const secondErr = await page.locator('.notice-error').count();
	const secondMsg = ((await page.locator('.notice-success').first().textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
	log('second apply message', secondMsg.slice(0, 100));
	ok('second apply is NOT a red error', secondErr === 0 && second.url().indexOf('haai_err') === -1, second.url().slice(-40));
	ok('second apply says it was already applied', secondMsg.indexOf('اعمال شده') !== -1, secondMsg.slice(0, 60));

	// and the product really carries the content
	await page.goto(admin + '/post.php?post=' + pid + '&action=edit', { waitUntil: 'load' });
	await page.waitForTimeout(1200);
	const body = await page.evaluate(() => { const c = document.querySelector('#content'); return c ? c.value : ''; });
	ok('the applied body is on the product', body.indexOf('<h2>') !== -1, body.slice(0, 40));

	await page.goto(screen, { waitUntil: 'load' });
	await page.screenshot({ path: 'dev-tests/shots/pc-gen-e2e.png', fullPage: true });
	console.log('\nC1 E2E: ' + (steps - fails) + ' ok / ' + fails + ' failed (of ' + steps + ')');
	await browser.close();
	process.exit(fails ? 1 : 0);
})();
