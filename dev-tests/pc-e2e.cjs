/**
 * End-to-end browser test: the whole product-content flow on the bench.
 *
 *   node dev-tests/pc-e2e.cjs [productId]
 *
 * Prerequisites (dev-tests/pc-e2e-run.sh sets them up): the mock LLM serves on
 * http://[::1]:8802 with allow_localhost = 1 (the legitimate dev path, so the
 * test does not depend on any hole in is_url_safe), the product-content module
 * is on, and the target product has no stored preview.
 *
 * Three FAQ scenarios are driven end to end, and the APPLIED PRODUCT is read
 * back from the editor to prove what really landed:
 *   edit   — add a question, delete the first one
 *   keep   — touch nothing (the model's FAQ must survive)
 *   remove — delete every row (the product must end up without a FAQ)
 *
 * Every step is logged with its result; failures are listed and never repaired.
 */
const { chromium } = require('C:/Users/user/.workbuddy-ai/binaries/node/workspace/node_modules/playwright');

const base = 'http://localhost/haai-test';
const admin = base + '/wp-admin';
const MODEL_Q1 = 'سؤال مدل یک؟';
const MODEL_Q2 = 'سؤال مدل دو؟';
const FAQ_HEADING = 'سؤالات متداول';

let fails = 0;
let steps = 0;

function log(label, value) {
	console.log('    · ' + label + (value === undefined ? '' : ': ' + value));
}
function ok(label, cond, extra) {
	steps++;
	if (!cond) fails++;
	console.log((cond ? '  ok   ' : '  FAIL ') + label.padEnd(52) + (extra === undefined ? '' : extra));
}
function info(label, value) {
	console.log('  info ' + label.padEnd(52) + (value === undefined ? '' : value));
}
const val = async (page, sel) => ((await page.inputValue(sel)) || '');

(async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1500, height: 1100 }, locale: 'fa-IR' });
	const page = await ctx.newPage();
	page.on('pageerror', (e) => console.log('  !! pageerror: ' + e.message));

	/* ---------- login ---------- */
	await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', 'haai_probe');
	await page.fill('#user_pass', 'Probe-2026-haai');
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/, { timeout: 20000 });
	ok('login', true);

	/* ---------- picker: rows, search, filter, selection ---------- */
	await page.goto(admin + '/admin.php?page=haai-product-content', { waitUntil: 'load' });
	// Wait for a real row: the placeholder is also a <tbody><tr>.
	await page.waitForSelector('.haai-prodtable tbody tr .haai-row-check', { timeout: 20000 });
	await page.waitForTimeout(600);
	log('rows on first page', await page.locator('.haai-prodtable tbody tr').count());
	log('total label', ((await page.textContent('[data-haai-pinfo]')) || '').trim());
	ok('picker loaded with rows', (await page.locator('.haai-prodtable tbody tr').count()) > 0);

	await page.fill('[data-haai-pq]', 'کفش');
	await page.waitForTimeout(1400);
	log('search "کفش" rows', await page.locator('.haai-prodtable tbody tr').count());
	ok('search narrows the list', (await page.locator('.haai-prodtable tbody tr').count()) > 0);

	await page.click('[data-haai-dd-btn]');
	await page.waitForTimeout(200);
	ok('filter panel opens', await page.evaluate(() => {
		const p = document.querySelector('[data-haai-dd-panel]');
		return !p.hasAttribute('hidden') && getComputedStyle(p).display !== 'none';
	}));
	await page.selectOption('[data-haai-pstatus]', ['publish']);
	await page.waitForTimeout(1200);
	log('active-filter badge', ((await page.textContent('[data-haai-dd-count]')) || '').trim());
	ok('filter badge counts the active filter', ((await page.textContent('[data-haai-dd-count]')) || '').trim() === '1');
	await page.keyboard.press('Escape');

	const firstRow = page.locator('.haai-prodtable tbody tr').first();
	log('picked product', ((await firstRow.locator('a').first().textContent()) || '').trim());
	await firstRow.locator('.haai-row-check').check();
	await page.waitForTimeout(250);
	const selCount = ((await page.textContent('[data-haai-selcount]')) || '').trim();
	log('selection bar', selCount);
	ok('selection is registered', selCount.indexOf('1') === 0, selCount);

	await firstRow.locator('a').first().click();
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	ok('product screen opened', ((await page.textContent('.haai-prodpicker__current')) || '').trim().length > 0);
	const pid = await page.evaluate(() => {
		const m = (document.querySelector('.haai-prodpicker__current') || {}).textContent || '';
		const hit = m.match(/#(\d+)/);
		return hit ? hit[1] : '';
	});
	log('product id', pid);

	const genForm = page.locator('form:has(input[name="action"][value="haai_pc_generate"])');

	/** Generate a fresh preview for the current product. */
	async function generate() {
		await genForm.locator('input[name="keywords"]').fill('کفش چرم، خرید');
		await genForm.locator('select[name="tone"]').selectOption('friendly');
		await genForm.locator('select[name="length"]').selectOption('long');
		await genForm.locator('button[type="submit"]').click();
		await page.waitForLoadState('load');
		await page.waitForTimeout(900);
		const notice = await page.$('.notice-error');
		if (notice) {
			info('generate error notice', ((await notice.textContent()) || '').trim().slice(0, 120));
		}
		return (await page.$('[data-haai-pvedit]')) !== null;
	}

	/** Tick the review-notes acknowledgement whenever the preview demands it. */
	async function ackNotes() {
		const ack = await page.$('#haai-pv-notesseen');
		if (!ack) return false;
		if (await ack.isChecked()) return true;
		await ack.click();
		await page.waitForTimeout(150);
		return true;
	}

	/** Read the applied product back from its edit screen. */
	async function readProduct() {
		await page.goto(admin + '/post.php?post=' + pid + '&action=edit', { waitUntil: 'load' });
		await page.waitForTimeout(1200);
		const out = await page.evaluate(() => {
			const g = (s) => document.querySelector(s);
			const slug = g('#editable-post-name-full') || g('#editable-post-name');
			return {
				title: g('#title') ? g('#title').value : '',
				content: g('#content') ? g('#content').value : '',
				excerpt: g('#excerpt') ? g('#excerpt').value : '',
				slug: slug ? slug.textContent.trim() : '',
			};
		});
		return out;
	}

	/** Back to the module screen for the next scenario. */
	async function backToScreen() {
		await page.goto(admin + '/admin.php?page=haai-product-content&product=' + pid, { waitUntil: 'load' });
		await page.waitForTimeout(900);
	}

	/* ================= scenario 1: edit the FAQ ================= */
	console.log('\n--- scenario «edit»: add one question, delete the first ---');
	ok('preview generated', await generate());
	const stamp = 'E2E' + Date.now();
	const t1 = 'عنوان ویرایش‌شده ' + stamp;
	const d1 = 'توضیح ویرایش‌شده ' + stamp + ' برای سنجش.';
	const s1 = 'e2e-' + stamp.toLowerCase();
	const b1 = '<h2>بدنه‌ی ویرایش‌شده</h2><p>' + stamp + '</p>';
	const q1 = 'سؤال تازه ' + stamp;
	const a1 = 'پاسخ تازه ' + stamp;

	await page.fill('#haai-pv-title', t1);
	await page.fill('#haai-pv-desc', d1);
	await page.fill('#haai-pv-slug', s1);
	await page.waitForTimeout(250);
	const counters = await page.evaluate(() => {
		const t = document.querySelector('[data-haai-countfor="haai-pv-title"]');
		const d = document.querySelector('[data-haai-countfor="haai-pv-desc"]');
		return { title: t ? t.textContent.trim() : null, desc: d ? d.textContent.trim() : null };
	});
	log('title counter', counters.title);
	log('desc counter', counters.desc);
	ok('title counter equals the real length', counters.title === t1.length + ' / 60', String(counters.title));
	ok('desc counter equals the real length', counters.desc === d1.length + ' / 155', String(counters.desc));

	await page.click('[data-haai-tab="src"]');
	await page.fill('[data-haai-pvsrc]', b1);
	await page.waitForTimeout(600);
	const words = ((await page.textContent('[data-haai-bodycount]')) || '').trim();
	// The counter counts what a reader sees: tags and entities are not words.
	const realWords = b1.replace(/<[^>]*>/g, ' ').replace(/&[a-z#0-9]+;/gi, ' ').trim().split(/\s+/).filter(Boolean).length;
	log('word counter', words);
	ok('word counter equals the real word count', words === realWords + ' واژه', words);
	await page.click('[data-haai-tab="view"]');
	await page.waitForTimeout(300);

	const before = await page.locator('[data-haai-faqrow]').count();
	await page.click('[data-haai-faqadd]');
	await page.waitForTimeout(200);
	const afterAdd = await page.locator('[data-haai-faqrow]').count();
	const last = page.locator('[data-haai-faqrow]').last();
	await last.locator('input[name^="faq["]').fill(q1);
	await last.locator('textarea[name^="faq["]').fill(a1);
	await page.locator('[data-haai-faqrow]').first().locator('[data-haai-faqdel]').click();
	await page.waitForTimeout(200);
	const afterDel = await page.locator('[data-haai-faqrow]').count();
	log('faq rows before → add → remove', before + ' → ' + afterAdd + ' → ' + afterDel);
	ok('FAQ row added', afterAdd === before + 1);
	ok('FAQ row removed', afterDel === afterAdd - 1);
	const names = await page.evaluate(() => Array.from(document.querySelectorAll('[data-haai-faqrow]')).map((r) => {
		const q = r.querySelector('input[name^="faq["]');
		return q ? q.name : '?';
	}));
	log('faq field names after removal', names.join(', '));
	ok('faq names are renumbered from zero', names.every((n, i) => n === 'faq[' + i + '][q]'), names.join(','));

	await ackNotes();
	await page.click('form.haai-pvform button[type="submit"].button-primary');
	await page.waitForLoadState('load');
	await page.waitForTimeout(800);
	const notice1 = await page.$('.notice-success, .notice-error');
	const text1 = notice1 ? ((await notice1.textContent()) || '').trim().replace(/\s+/g, ' ') : '(no notice)';
	log('apply notice', text1.slice(0, 120));
	ok('apply reported success', (await page.$('.notice-error')) === null && text1.indexOf('اعمال') !== -1, text1.slice(0, 70));
	ok('preview gone after apply', (await page.$('[data-haai-pvedit]')) === null);

	const p1 = await readProduct();
	log('saved title', p1.title);
	log('saved slug', p1.slug);
	log('saved excerpt', p1.excerpt.slice(0, 50));
	ok('edited title applied', p1.title === t1, p1.title);
	ok('edited slug applied', p1.slug.indexOf('e2e-') === 0, p1.slug);
	ok('edited meta became the excerpt', p1.excerpt.indexOf(stamp) !== -1);
	ok('edited body applied', p1.content.indexOf(stamp) !== -1);
	ok('FAQ heading written', p1.content.indexOf(FAQ_HEADING) !== -1);
	ok('the added question is in the product', p1.content.indexOf(q1) !== -1);
	ok('the added answer is in the product', p1.content.indexOf(a1) !== -1);
	ok('the deleted question is gone', p1.content.indexOf(MODEL_Q1) === -1);
	ok('the surviving model question is kept', p1.content.indexOf(MODEL_Q2) !== -1);

	/* ================= scenario 2: leave the FAQ alone ================= */
	console.log('\n--- scenario «keep»: the model FAQ must survive ---');
	await backToScreen();
	ok('preview generated', await generate());
	const faqRows2 = await page.locator('[data-haai-faqrow]').count();
	log('faq rows left untouched', faqRows2);
	await ackNotes();
	await page.click('form.haai-pvform button[type="submit"].button-primary');
	await page.waitForLoadState('load');
	await page.waitForTimeout(800);
	ok('apply reported success', (await page.$('.notice-error')) === null);
	const p2 = await readProduct();
	ok('both model questions survive', p2.content.indexOf(MODEL_Q1) !== -1 && p2.content.indexOf(MODEL_Q2) !== -1);
	ok('FAQ heading present', p2.content.indexOf(FAQ_HEADING) !== -1);

	/* ================= scenario 3: remove every FAQ row ================= */
	console.log('\n--- scenario «remove»: no FAQ must be written ---');
	await backToScreen();
	ok('preview generated', await generate());
	let guard = 0;
	while ((await page.locator('[data-haai-faqrow]').count()) > 0 && guard < 10) {
		await page.locator('[data-haai-faqrow]').first().locator('[data-haai-faqdel]').click();
		await page.waitForTimeout(150);
		guard++;
	}
	log('faq rows after deleting all', await page.locator('[data-haai-faqrow]').count());
	ok('every FAQ row removed in the UI', (await page.locator('[data-haai-faqrow]').count()) === 0);
	await ackNotes();
	await page.click('form.haai-pvform button[type="submit"].button-primary');
	await page.waitForLoadState('load');
	await page.waitForTimeout(800);
	ok('apply reported success', (await page.$('.notice-error')) === null);
	const p3 = await readProduct();
	log('content length', p3.content.length);
	ok('no FAQ heading in the product', p3.content.indexOf(FAQ_HEADING) === -1);
	ok('no model question in the product', p3.content.indexOf(MODEL_Q1) === -1 && p3.content.indexOf(MODEL_Q2) === -1);
	ok('the body itself is still written', p3.content.indexOf('<h2>') !== -1, p3.content.slice(0, 60));

	/* ================= scenario 4: a pasted note must not publish ================= */
	console.log('\n--- scenario «notes»: a bracketed note is moved, never published ---');
	const NOTE = 'این بند واقعی محصول است.';
	await backToScreen();
	ok('preview generated', await generate());
	await page.locator('#haai-pvtab-src').click();
	await page.locator('[data-haai-pvsrc]').fill('<p>' + NOTE + ' [نیاز به داده واقعی: گارانتی]</p>');
	await page.waitForTimeout(400);
	// Acknowledge the notes the preview shipped with; the pasted one is what must
	// still be refused.
	await ackNotes();
	await page.click('form.haai-pvform button[type="submit"].button-primary');
	await page.waitForLoadState('load');
	await page.waitForTimeout(900);
	const err4 = await page.$('.notice-error');
	const errText4 = err4 ? ((await err4.textContent()) || '').trim().replace(/\s+/g, ' ') : '';
	info('apply blocked with', errText4.slice(0, 110));
	ok('applying a pasted note is refused', errText4.indexOf('یادداشت') !== -1, errText4.slice(0, 60));
	ok('the preview survived the refusal', (await page.$('[data-haai-pvedit]')) !== null);
	ok('the notes panel is now rendered', (await page.$('[data-haai-notes]')) !== null);
	const panelText = (await page.textContent('[data-haai-notes]')) || '';
	ok('the panel names the missing data', panelText.indexOf('گارانتی') !== -1, panelText.replace(/\s+/g, ' ').slice(0, 90));
	ok('the acknowledgement tick is required', (await page.$('#haai-pv-notesseen')) !== null);
	ok('apply is disabled before the tick', await page.$eval('form.haai-pvform button[type="submit"].button-primary', (b) => b.disabled === true));
	await page.click('#haai-pv-notesseen');
	await page.waitForTimeout(200);
	ok('apply unlocks after the tick', await page.$eval('form.haai-pvform button[type="submit"].button-primary', (b) => b.disabled === false));
	await page.click('form.haai-pvform button[type="submit"].button-primary');
	await page.waitForLoadState('load');
	await page.waitForTimeout(900);
	ok('the second apply went through', (await page.$('.notice-error')) === null);
	const p4 = await readProduct();
	ok('the note never reached the page', p4.content.indexOf('[نیاز') === -1 && p4.content.indexOf('گارانتی') === -1, p4.content.slice(0, 70));
	ok('the real prose did reach the page', p4.content.indexOf(NOTE) !== -1);

	await page.screenshot({ path: 'dev-tests/shots/e2e-applied.png', fullPage: true });
	console.log('\nE2E: ' + (steps - fails) + ' ok / ' + fails + ' failed (of ' + steps + ')');
	await browser.close();
	process.exit(fails ? 1 : 0);
})();
