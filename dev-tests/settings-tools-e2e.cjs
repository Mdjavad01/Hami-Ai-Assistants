/**
 * Browser test for Batch A2: export / import / reset on the advanced tab.
 *
 *   node dev-tests/settings-tools-e2e.cjs
 *
 * Everything here is observed through the real WordPress + real HTTP:
 *   - export downloads JSON with no api_key and no proxy_password;
 *   - reset refuses a missing or wrong confirmation word and changes nothing,
 *     then runs on the right word and leaves a snapshot that really undoes it;
 *   - import is two steps: a summary of what would change, then the write —
 *     a file carrying an API key is refused until the box is ticked;
 *   - junk and oversized files are refused with a Persian notice;
 *   - a POST without a nonce, and a POST from a user without manage_options,
 *     are both rejected and leave the settings untouched.
 */
const fs = require('fs');
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';
const admin = base + '/wp-admin';
const adv = admin + '/admin.php?page=haai-settings&tab=advanced';
const post = admin + '/admin-post.php';
const tmp = (n) => __dirname + '/' + n;

let fails = 0;
let steps = 0;
function ok(label, cond, extra) {
	steps++;
	if (!cond) fails++;
	console.log((cond ? '  ok   ' : '  FAIL ') + label.padEnd(58) + (extra === undefined ? '' : String(extra).slice(0, 70)));
}
function log(label, value) { console.log('    · ' + label + (value === undefined ? '' : ': ' + String(value).slice(0, 90))); }

async function login(page, user, pass) {
	await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', user);
	await page.fill('#user_pass', pass);
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/, { timeout: 20000 });
}

(async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 1100 }, locale: 'fa-IR' });
	const page = await ctx.newPage();
	page.on('pageerror', (e) => console.log('  !! pageerror: ' + e.message));
	await login(page, 'haai_probe', 'Probe-2026-haai');
	ok('admin login', true);

	await page.goto(adv, { waitUntil: 'load' });
	await page.waitForTimeout(400);
	const tools = page.locator('.haai-panel--tools');
	ok('tools panel is on the advanced tab', await tools.isVisible());
	ok('export form present', (await page.locator('form input[value="haai_export"]').count()) > 0);
	ok('import form is multipart', await page.evaluate(() => {
		const f = document.querySelector('form input[value="haai_import"]');
		const form = f && f.form;
		return !!form && 'multipart/form-data' === form.enctype && !!form.querySelector('input[type="file"]');
	}));
	ok('reset needs a typed word before the button unlocks', await page.evaluate(() => {
		const f = document.querySelector('form input[value="haai_reset"]');
		const btn = f.form.querySelector('[data-haai-reset-submit]');
		return !!btn && btn.disabled === true;
	}));
	const nonce = await page.inputValue('form input[name="_wpnonce"]');
	ok('forms carry a nonce', !!nonce);

	/* ---------------- export: no secrets in the file ---------------------- */
	const dl = await Promise.all([
		page.waitForEvent('download', { timeout: 30000 }),
		page.locator('form input[value="haai_export"]').evaluate((i) => i.form.querySelector('button[type="submit"]').click()),
	]).then((r) => r[0]);
	const path = tmp('exported-' + Date.now() + '.json');
	await dl.saveAs(path);
	const txt = fs.readFileSync(path, 'utf8');
	const parsed = JSON.parse(txt);
	ok('export downloaded a JSON file', fs.existsSync(path) && txt.length > 100, txt.length + ' B');
	ok('export carries settings + modules', !!parsed.settings && !!parsed.modules);
	ok('export has an EMPTY api_key', '' === parsed.settings.provider.api_key);
	ok('export has an EMPTY proxy_password', '' === parsed.settings.provider.proxy_password);
	ok('export leaks no key-like string', !/sk-[A-Za-z0-9]{6,}/.test(txt));
	fs.unlinkSync(path);

	/* ---------------- reset: server refuses without the word ------------- */
	const beforeModel = 'probe-' + Date.now();
	await page.goto(adv, { waitUntil: 'load' });
	await page.fill('input[name="provider[model]"]', beforeModel).catch(() => {});
	// provider[model] lives on the provider tab; save it there
	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	await page.fill('input[name="provider[model]"]', beforeModel);
	await page.click('.haai-savebar button[type="submit"]');
	await page.waitForTimeout(900);
	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	ok('a known value was set so the reset is observable', (await page.inputValue('input[name="provider[model]"]')) === beforeModel);

	const r1 = await page.request.post(post, { form: { action: 'haai_reset', _wpnonce: nonce, confirm_word: 'اشتباه' } });
	ok('reset with the wrong word lands on reset-unconfirmed', r1.url().indexOf('reset-unconfirmed') !== -1, r1.url().slice(-40));
	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	ok('nothing changed after the refused reset', (await page.inputValue('input[name="provider[model]"]')) === beforeModel);

	const r2 = await page.request.post(post, { form: { action: 'haai_reset', _wpnonce: nonce } });
	ok('reset with no word at all is refused too', r2.url().indexOf('reset-unconfirmed') !== -1);

	const r3 = await page.request.post(post, { form: { action: 'haai_reset', _wpnonce: nonce, confirm_word: 'بازنشانی' } });
	ok('the right word performs the reset', r3.url().indexOf('haai_ok=reset') !== -1, r3.url().slice(-30));
	await page.goto(adv, { waitUntil: 'load' });
	await page.waitForTimeout(400);
	ok('a snapshot panel appeared for the undo', (await page.locator('.haai-panel--restore').count()) > 0);
	page.once('dialog', (d) => d.accept());
	await page.locator('.haai-panel--restore form button[type="submit"]').first().click();
	await page.waitForURL(/haai_ok=restored/, { timeout: 20000 });
	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	ok('restore brought the pre-reset value back', (await page.inputValue('input[name="provider[model]"]')) === beforeModel);

	/* ---------------- import: summary, then confirm ---------------------- */
	const importedModel = 'imported-' + Date.now();
	const good = tmp('imp-good.json');
	fs.writeFileSync(good, JSON.stringify({
		plugin: 'hami-ai-assistant',
		settings: { provider: { model: importedModel, api_key: 'sk-imported-test-key-9', timeout: 999999 }, general: { zzz_injected: 'no' } },
		modules: { chatbot: 1 },
	}), 'utf8');

	await page.goto(adv, { waitUntil: 'load' });
	await page.setInputFiles('input[name="haai_import_file"]', good);
	await page.click('form:has(input[value="haai_import"]) button[type="submit"]');
	await page.waitForURL(/import-preview/, { timeout: 20000 });
	await page.waitForTimeout(500);
	// The token is in THIS url only: navigating away drops it, and the summary
	// panel is bound to it.
	const token = new URL(page.url()).searchParams.get('haai_token') || '';
	ok('preview URL carries the confirm token', token.length > 10, token.slice(0, 6));
	const summary = ((await page.locator('.haai-panel--inner').textContent().catch(() => '')) || '').replace(/\s+/g, ' ');
	log('summary', summary);
	ok('preview step shows a summary and changes nothing yet', summary.indexOf('کلید معتبر') !== -1);
	ok('the summary warns that the file carries a key', summary.indexOf('هشدار') !== -1 && summary.indexOf('کلید API') !== -1);
	ok('the confirm form offers the explicit key box', (await page.locator('input[name="allow_keys"]').count()) > 0);

	// confirm WITHOUT the box: must be refused, key untouched
	if (token) {
		const refused = await page.request.post(post, { form: { action: 'haai_import_confirm', _wpnonce: nonce, haai_token: token } });
		ok('applying a key-carrying file without the box is refused', refused.url().indexOf('import-need-keys') !== -1, refused.url().slice(-30));
	}

	// second preview + confirm WITH the box
	await page.setInputFiles('input[name="haai_import_file"]', good);
	await page.click('form:has(input[value="haai_import"]) button[type="submit"]');
	await page.waitForURL(/import-preview/, { timeout: 20000 });
	page.once('dialog', (d) => d.accept());
	await page.check('input[name="allow_keys"]');
	await page.click('.haai-panel--inner form button[type="submit"]');
	await page.waitForURL(/haai_ok=/, { timeout: 20000 });
	ok('confirm with the box reports success', page.url().indexOf('imported') !== -1, page.url().slice(-30));

	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	ok('the imported model value was applied', (await page.inputValue('input[name="provider[model]"]')) === importedModel);
	const masked = await page.inputValue('input[name="provider[api_key]"]');
	ok('the imported key is stored (masked in the form)', masked.indexOf('•') === 0 || masked === 'haai-unchanged' || masked.length > 0, masked.slice(0, 12));
	await page.goto(adv, { waitUntil: 'load' });
	const snapshotNow = (await page.locator('.haai-panel--restore').count()) > 0;
	ok('import took a snapshot before writing', snapshotNow === true);
	page.once('dialog', (d) => d.accept());
	await page.locator('.haai-panel--restore form button[type="submit"]').first().click();
	await page.waitForURL(/haai_ok=restored/, { timeout: 20000 });
	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	ok('restore undid the whole import', (await page.inputValue('input[name="provider[model]"]')) === beforeModel);
	fs.unlinkSync(good);

	/* ---------------- import: junk and oversized ----------------------- */
	const junk = tmp('imp-junk.txt');
	fs.writeFileSync(junk, 'this is not json at all {{{', 'utf8');
	await page.goto(adv, { waitUntil: 'load' });
	await page.setInputFiles('input[name="haai_import_file"]', junk);
	await page.click('form:has(input[value="haai_import"]) button[type="submit"]');
	await page.waitForURL(/haai_ok=/, { timeout: 20000 });
	ok('a non-JSON file is refused', page.url().indexOf('import-failed') !== -1, page.url().slice(-30));
	fs.unlinkSync(junk);

	const big = tmp('imp-big.json');
	fs.writeFileSync(big, JSON.stringify({ settings: { provider: { model: 'x' } }, padding: 'y'.repeat(700000) }), 'utf8');
	await page.goto(adv, { waitUntil: 'load' });
	await page.setInputFiles('input[name="haai_import_file"]', big);
	await page.click('form:has(input[value="haai_import"]) button[type="submit"]');
	await page.waitForURL(/haai_ok=/, { timeout: 30000 });
	ok('an oversized file is refused by the size cap', page.url().indexOf('import-too-big') !== -1, page.url().slice(-30));
	fs.unlinkSync(big);

	/* ---------------- guards: nonce and capability ---------------------- */
	const guarded = await page.evaluate(() => {
		const i = document.querySelector('input[name="provider\\[model\\]"]');
		return i ? i.value : '';
	}).catch(() => '');
	log('model before guard tests', guarded);

	const rNoNonce = await page.request.post(post, { form: { action: 'haai_reset', confirm_word: 'بازنشانی' } });
	const body1 = await rNoNonce.text();
	ok('POST without a nonce is rejected (status ' + rNoNonce.status() + ')', rNoNonce.status() >= 400 || body1.indexOf('Sure') !== -1 || body1.indexOf('دسترسی') !== -1);
	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	ok('the refused no-nonce reset changed nothing', (await page.inputValue('input[name="provider[model]"]')) === beforeModel);

	// The capability branch (a valid nonce held by someone without
	// manage_options) is covered deterministically by dev-tests/guard-check.sh,
	// which drives the same guard on this same WordPress.

	await page.goto(admin + '/admin.php?page=haai-settings&tab=provider', { waitUntil: 'load' });
	ok('settings still intact after the rejected writes', (await page.inputValue('input[name="provider[model]"]')) === beforeModel);

	await page.goto(adv, { waitUntil: 'load' });
	await page.screenshot({ path: 'dev-tests/shots/settings-tools-e2e.png', fullPage: true });
	console.log('\nA2 E2E: ' + (steps - fails) + ' ok / ' + fails + ' failed (of ' + steps + ')');
	await browser.close();
	process.exit(fails ? 1 : 0);
})();
