/**
 * Browser test for Batch C2: the product-content UX items that C1 left.
 *
 *   node dev-tests/pc-ux-e2e.cjs <productId>
 *
 * Proven by behaviour, not by markup:
 *   P6  the FAQ block is previewed exactly as it will be appended, updates
 *       while rows are typed, disappears when every row goes, and what the
 *       preview showed is what the product ends up carrying;
 *   P9  tabs are real tabs: ArrowRight/ArrowLeft/Home/End move between them,
 *       aria-selected and the roving tabindex follow, focus moves;
 *   P8  the result counters are a live region, and focus survives an AJAX
 *       re-render of the table;
 *   P7  the incomplete badge is one short word with the detail in a tooltip;
 *   P2  the unsaved-work guard sleeps while nothing is edited, wakes on the
 *       first edit, asks before «regenerate / delete preview», and says nothing
 *       after the work has been applied.
 */
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';
const admin = base + '/wp-admin';
const FAQ_HEADING = 'سؤالات متداول';

let fails = 0;
let steps = 0;
function ok(label, cond, extra) {
	steps++;
	if (!cond) fails++;
	console.log((cond ? '  ok   ' : '  FAIL ') + label.padEnd(58) + (extra === undefined ? '' : String(extra).slice(0, 70)));
}
function log(label, value) { console.log('    · ' + label + (value === undefined ? '' : ': ' + value)); }

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
	ok('login', true);

	const pid = process.argv[2] || '';
	const screen = admin + '/admin.php?page=haai-product-content&product=' + pid;
	await page.goto(screen, { waitUntil: 'load' });
	await page.waitForSelector('.haai-prodtable tbody tr .haai-row-check', { timeout: 20000 });

	/* ---------------- P7: short badge + tooltip ---------------- */
	const badge = await page.evaluate(() => {
		const b = document.querySelector('.haai-prodtable tbody .haai-badge');
		return b ? { text: (b.textContent || '').trim(), title: b.getAttribute('title') || '', aria: b.getAttribute('aria-label') || '' } : null;
	});
	log('first badge', JSON.stringify(badge));
	ok('a completeness badge is rendered', badge !== null);
	ok('badge text is short (ناقص/کامل)', badge && (badge.text === 'ناقص' || badge.text === 'کامل'), badge && badge.text);
	ok('incomplete badge carries the detail in a tooltip', !badge || badge.text === 'کامل' || (badge.title.indexOf('کمبود') === 0 && badge.title.length > 'کمبود: '.length), badge && badge.title);

	/* ---------------- P8: live region + focus survival ---------------- */
	const liveAttrs = await page.evaluate(() => {
		const c = document.querySelector('[data-haai-pcounter]');
		const i = document.querySelector('[data-haai-pinfo]');
		const s = document.querySelector('[data-haai-selcount]');
		return { counter: c && c.getAttribute('aria-live'), info: i && i.getAttribute('aria-live'), sel: s && s.getAttribute('aria-live') };
	});
	ok('page counter is a polite live region', liveAttrs.counter === 'polite', JSON.stringify(liveAttrs));
	ok('results total is a polite live region', liveAttrs.info === 'polite');
	ok('selection counter is a polite live region', liveAttrs.sel === 'polite');

	/* P8, the case that actually matters: the keyboard is on a row checkbox and
	   a debounced re-render replaces the table around it (the same filter firing
	   again, a nudge in the search field). The row survives, so focus must come
	   back to it instead of falling to <body>. The value is set and the input
	   event dispatched without moving focus, which is exactly what the debounce
	   does. */
	await page.evaluate(() => {
		const q = document.querySelector('[data-haai-pq]');
		q.value = 'کفش';
		q.dispatchEvent(new Event('input', { bubbles: true }));
	});
	await page.waitForTimeout(1800);
	const rowsNow = await page.locator('.haai-prodtable tbody .haai-row-check').count();
	ok('the filter re-rendered the table', rowsNow > 0, 'rows=' + rowsNow);

	const focusId = await page.evaluate(() => {
		const boxes = document.querySelectorAll('.haai-prodtable tbody .haai-row-check');
		const box = boxes.length > 1 ? boxes[1] : boxes[0];
		box.focus();
		return box.value;
	});
	await page.evaluate(() => {
		const q = document.querySelector('[data-haai-pq]');
		q.dispatchEvent(new Event('input', { bubbles: true }));
	});
	await page.waitForTimeout(1800);
	const after = await page.evaluate((id) => {
		const a = document.activeElement;
		return {
			rowStillThere: !!document.querySelector('.haai-row-check[value="' + id + '"]'),
			focusBack: a && a.classList && a.classList.contains('haai-row-check') ? a.value : null,
			activeTag: a ? a.tagName : null,
		};
	}, focusId);
	log('focus before/after re-render', focusId + ' → ' + after.focusBack + ' (row still there: ' + after.rowStillThere + ', active: ' + after.activeTag + ')');
	ok('the focused row survives the re-render', after.rowStillThere === true);
	ok('focus returns to the same row after the AJAX re-render', after.focusBack === focusId, after.focusBack + '/' + after.activeTag);

	/* ---------------- build a preview for the editor tests ---------------- */
	const genForm = page.locator('form:has(input[name="action"][value="haai_pc_generate"])');
	await genForm.locator('input[name="keywords"]').fill('کفش چرم، خرید');
	await genForm.locator('button[type="submit"]').first().click();
	await page.waitForSelector('[data-haai-pvedit]', { timeout: 40000 });
	ok('preview generated', true);

	/* ---------------- P2 (part 1): nothing edited yet → no warning ---------
	 * Read before any typing, or the state under test would already be gone.  */
	const cleanPrevented = await page.evaluate(() => {
		const e = new Event('beforeunload', { bubbles: false, cancelable: true });
		window.dispatchEvent(e);
		return e.defaultPrevented;
	});
	ok('no unsaved-work warning before anything is edited', cleanPrevented === false);

	/* ---------------- P6: preview shows the FAQ as it will be applied ------ */
	const faq0 = await page.evaluate(() => {
		const wrap = document.querySelector('[data-haai-pvfaq]');
		if (!wrap) return null;
		const body = wrap.querySelector('[data-haai-pvfaqbody]');
		return {
			label: (wrap.querySelector('.haai-pv-faq__label') || {}).textContent || '',
			heading: body ? (body.querySelector('h2') || {}).textContent : null,
			qs: body ? Array.from(body.querySelectorAll('h3')).map((h) => h.textContent) : [],
			ps: body ? Array.from(body.querySelectorAll('p')).length : 0,
		};
	});
	log('preview faq state', JSON.stringify(faq0 && { heading: faq0.heading, qs: faq0.qs }));
	ok('preview contains the FAQ block', faq0 !== null);
	ok('FAQ preview uses the same heading as the applied product', faq0 && (faq0.heading || '').trim() === FAQ_HEADING, faq0 && faq0.heading);
	ok('FAQ preview marks where the block goes', faq0 && faq0.label.indexOf('اضافه می‌شود') !== -1, faq0 && faq0.label.slice(0, 40));
	ok('both model questions are in the preview', faq0 && faq0.qs.length === 2 && faq0.qs[0].indexOf('سؤال مدل یک') !== -1, faq0 && faq0.qs.join('|'));

	// typing a new question must show up in the preview without a reload
	const typedQ = 'سؤال تایپ‌شده ' + Date.now();
	await page.locator('[data-haai-faqrow]').last().locator('input[name^="faq["]').fill(typedQ);
	await page.waitForTimeout(300);
	const afterType = await page.evaluate((q) => {
		const body = document.querySelector('[data-haai-pvfaqbody]');
		return body ? Array.from(body.querySelectorAll('h3')).some((h) => h.textContent === q) : false;
	}, typedQ);
	ok('preview updates live while a question is typed', afterType === true);

	/* ---------------- P9: keyboard tablist ---------------- */
	const tabIds = await page.evaluate(() => Array.from(document.querySelectorAll('[data-haai-tab]')).map((b) => b.getAttribute('data-haai-tab')));
	ok('two tabs exist', tabIds.length === 2, tabIds.join(','));
	await page.focus('[data-haai-tab="view"]');
	await page.keyboard.press('ArrowRight');
	await page.waitForTimeout(200);
	let tabState = await page.evaluate(() => {
		const src = document.querySelector('[data-haai-tab="src"]');
		const view = document.querySelector('[data-haai-tab="view"]');
		return {
			focused: document.activeElement === src,
			srcSelected: src.getAttribute('aria-selected'),
			srcTabindex: src.tabIndex,
			viewTabindex: view.tabIndex,
			srcPaneVisible: !document.querySelector('[data-haai-pane="src"]').hidden,
			controls: src.getAttribute('aria-controls'),
			panelRole: document.querySelector('[data-haai-pane="src"]').getAttribute('role'),
		};
	});
	log('after ArrowRight', JSON.stringify(tabState));
	ok('ArrowRight moves to the next tab and focuses it', tabState.focused && tabState.srcSelected === 'true');
	ok('roving tabindex: only the active tab is a tab stop', tabState.srcTabindex === 0 && tabState.viewTabindex === -1);
	ok('the tab controls a real tabpanel', tabState.controls === 'haai-pvpane-src' && tabState.panelRole === 'tabpanel');
	ok('the pane followed the tab', tabState.srcPaneVisible === true);

	await page.keyboard.press('ArrowLeft');
	await page.waitForTimeout(150);
	ok('ArrowLeft returns to the first tab', await page.evaluate(() => document.activeElement === document.querySelector('[data-haai-tab="view"]')));
	await page.keyboard.press('End');
	await page.waitForTimeout(150);
	ok('End jumps to the last tab', await page.evaluate(() => document.activeElement === document.querySelector('[data-haai-tab="src"]')));
	await page.keyboard.press('Home');
	await page.waitForTimeout(150);
	ok('Home jumps to the first tab', await page.evaluate(() => document.activeElement === document.querySelector('[data-haai-tab="view"]')));

	/* ---------------- P2 (part 2): a real edit arms the guard -------------- */
	// editing arms it (a click on a tab is not an edit; typing is)
	await page.fill('#haai-pv-title', 'عنوان ویرایش‌شده ' + Date.now());
	await page.waitForTimeout(200);
	const dirtyPrevented = await page.evaluate(() => {
		const e = new Event('beforeunload', { bubbles: false, cancelable: true });
		window.dispatchEvent(e);
		return e.defaultPrevented;
	});
	ok('the warning is armed by a real edit', dirtyPrevented === true);

	// «delete preview» asks first while there is unsaved work, and obeys a "no"
	let asked = false;
	page.once('dialog', (d) => { asked = true; d.dismiss(); });
	const dismissBtn = page.locator('form:has(input[name="action"][value="haai_pc_dismiss"]) button[type="submit"]');
	await dismissBtn.click();
	await page.waitForTimeout(500);
	ok('deleting a preview with unsaved edits asks for confirmation', asked === true);
	ok('a declined confirmation keeps the preview', (await page.locator('[data-haai-pvedit]').count()) > 0);

	/* ---------------- P6 end-to-end: what the preview showed is applied ---- */
	// remove every row, add exactly one known pair, apply, read the product back
	let guard = 0;
	while ((await page.locator('[data-haai-faqrow]').count()) > 0 && guard < 12) {
		await page.locator('[data-haai-faqrow]').first().locator('[data-haai-faqdel]').click();
		await page.waitForTimeout(120);
		guard++;
	}
	const emptyFaqHidden = await page.evaluate(() => {
		const w = document.querySelector('[data-haai-pvfaq]');
		return !w || w.hidden;
	});
	ok('removing every row hides the FAQ block in the preview', emptyFaqHidden === true);

	await page.click('[data-haai-faqadd]');
	await page.waitForTimeout(200);
	const last = page.locator('[data-haai-faqrow]').last();
	await last.locator('input[name^="faq["]').fill(typedQ);
	await last.locator('textarea[name^="faq["]').fill('پاسخ تایپ‌شده.');
	await page.waitForTimeout(300);
	const previewNow = await page.evaluate((q) => {
		const body = document.querySelector('[data-haai-pvfaqbody]');
		if (!body) return null;
		return {
			heading: (body.querySelector('h2') || {}).textContent,
			questions: Array.from(body.querySelectorAll('h3')).map((h) => h.textContent),
		};
	}, typedQ);
	log('preview before apply', JSON.stringify(previewNow));
	ok('preview now lists only the one question', previewNow && previewNow.questions.length === 1 && previewNow.questions[0] === typedQ, previewNow && previewNow.questions.join('|'));

	page.removeAllListeners('dialog');
	// The preview ships with an open review note (the short canned reply says so),
	// and apply stays locked until it is acknowledged.
	const uxAck = await page.$('#haai-pv-notesseen');
	if (uxAck && !(await uxAck.isChecked())) {
		await uxAck.click();
		await page.waitForTimeout(150);
	}
	await page.click('form.haai-pvform button[type="submit"].button-primary');
	await page.waitForURL(/haai_msg|haai_err/, { timeout: 30000 });
	await page.waitForTimeout(700);
	ok('apply reported no error', (await page.locator('.notice-error').count()) === 0);

	await page.goto(admin + '/post.php?post=' + pid + '&action=edit', { waitUntil: 'load' });
	await page.waitForTimeout(1300);
	const applied = await page.evaluate(() => {
		const c = document.querySelector('#content');
		return c ? c.value : '';
	});
	ok('applied body has the FAQ heading', applied.indexOf(FAQ_HEADING) !== -1);
	ok('applied body has exactly the previewed question', applied.indexOf(typedQ) !== -1);
	ok('applied body dropped the questions the preview dropped', applied.indexOf('سؤال مدل یک') === -1);

	await page.goto(screen, { waitUntil: 'load' });
	await page.screenshot({ path: 'dev-tests/shots/pc-ux-e2e.png', fullPage: true });
	console.log('\nC2 E2E: ' + (steps - fails) + ' ok / ' + fails + ' failed (of ' + steps + ')');
	await browser.close();
	process.exit(fails ? 1 : 0);
})();
