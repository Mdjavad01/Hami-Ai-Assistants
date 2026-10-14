# طرح آماده‌ی اجرای دسته C (فلوی کامل UX محتوای محصول) — ✅ اجرا شد (C1 + C2، ۲۰۲۶-۱۰-۱۳)

> به‌روز: همه‌ی P2 تا P9 پیاده و با مرورگر تأیید شدند (C1 → tag `after-autobatch-C1`، C2 → tag `after-autobatch-C2`؛ BL-01 هم بسته شد). شواهد: `REPORT-AUTONOMOUS.md`. این فایل به‌عنوان نقشه‌ی مرجع/تاریخی می‌ماند.

> **توجه (۲۰۲۶-۱۰-۱۴):** شماره‌خطوطِ «نقشه‌ی کد» پایین، مربوط به همان روز است و گام ۲ (الف تا ط) همان فایل‌ها را بزرگ‌تر کرد؛ برای ادرس‌های امروز به `docs.md` (بخش 7.1) و `dev-notes/PROGRESS.md` مراجعه کنید، نه این شماره‌ها.

## نقشه‌ی کد (فایل:خط، از بازبینی همین جلسه)
- صفحه/مارک‌آپ: `modules/class-haa-module-product-content.php:167` `render_screen()`
  - فرم تولید: خط ۲۳۲–۲۶۷؛ دکمه‌ی «ساخت پیش‌نمایش» خط ۲۶۵ (`haai_pc_generate`).
  - فرم اعمال/پیش‌نمایش (`data-haai-pvedit`): ۲۷۵–۳۶۵. تب‌ها نقش `role=tab`/`tablist` دارند (۳۱۱–۳۱۵) ولی **`aria-controls` و `tabindex` roving و کلیدهای ←/→/Home/End ندارند** و panes نقش `role=tabpanel` ندارند (۳۱۶–۳۲۲).
  - بخش FAQ قابل‌ویرایش: ۳۲۶–۳۵۹؛ شمارنده‌ها/نتیجه و `aria-live` هنوز گذاشته نشده.
  - دکمه‌های تصویر/حذف پیش‌نمایش: ۳۶۷–۳۸۲.
- JS: `assets/js/product-picker.js` (root `data-haai-prodpicker`؛ تب‌ها ۴۷۱–۴۹۱؛ فرم‌ها submit ۵۵۶–۵۶۲؛ `go()` رندر AJAX ۳۷۳–۳۸۸).
- هندلرها: `handle_generate` (۱۳۰۸)، `handle_apply` (۱۵۷۲)، `merge_edits` (۱۴۸۹)، `back()` (۱۶۷۸). پیام خطا از `generate_brief` (۱۲۶۳) → `Haa_Content::product_brief` (class-haa-content.php:158) → `self::generate()` (WP_Error → `get_error_message()`؛ اینجا باید به پیام‌های فارسی راهنmaidار شکسته شود).
- FAQ نهایی: `Haa_Seo::apply()` (class-haa-seo.php:26) بدنه را `html + faq_html(faq)` می‌سازد؛ `faq_html` با `<h2>سؤالات متداول</h2>` (خط ۱۱۶–۱۲۱) → مرجع برای P6.

## کارها (هرکدام commit + تست مرورگر)
- **P3** (دوبار-ارسال): JS — هنگام submit فرم تولید، دکمه disabled + متن «در حال ساخت…»؛ سرور — قفل کوتاه‌مدت به‌ازای هر محصول: `set_transient('haai_pc_gen_'.$pid,1,60)` در ابتدای `handle_generate`؛ اگر از قبل بود → پیام «ساخت همین محصول در حال انجام است…»، در پایان آزاد شود.
- **P5** (پیام خطا): در مسیر `Haa_Content::generate()`/`product_brief` کد خطا (timeout/cURL، HTTP 504/429/401/402، پاسخ JSON نامعتبر) را به پیام فارسی روشن + راهنما نگاشت کن: «دوباره بزنید» / «تنظیمات ارائه‌دهنده را بررسی کنید» / «مهلت پاسخ کوتاه است». تست با mock گسترش‌یافته (تاخیر + وضعیت‌های HTTP).
- **P4** (اعمال idempotent): پس از اعمال موفق `set_transient('haai_pc_applied_'.$pid, time(), 5*MIN)`. در `handle_apply` اگر preview نبود ولی marker اعمال تازه بود → پیام **سبز/تکراری** «این محتوا همین حالا اعمال شده است» (نه notice-قرمز). تست: اعمال دوباره → پیام موفقیت، نه خطا.
- **P2** (ترک صفحه/تأیید): JS — هر تغییر در `data-haai-pvedit` حالت dirty؛ `beforeunload` فقط وقتی dirty. «تولید دوباره» و «حذف پیش‌نمایش» اگر dirty → `window.confirm`.
- **P6** (FAQ پیش‌نمایش==نهایی): pane «view» باید بدنه + بلوک FAQ را دقیقاً مثل `Haa_Seo::faq_html()` با برچسب «سؤالات متداول» نشان دهد و با ویرایش ردیف‌های FAQ زنده به‌روز شود.
- **P7** (بَج ناقص کوتاه + tooltip): `contentBadge()` در product-picker.js:۲۵۴ — متن کوتاه + `title` کامل.
- **P8** (aria-live/فوکوس): روی شمارنده‌ی نتایج `aria-live="polite"`؛ پس از رندر `go()` فوکوس را برگردان (ذخیره‌ی `document.activeElement` کلیدی قبل fetch، بازگرداندن بعد render).
- **P9** (کیبورد تب‌ها): `role=tab`+`aria-controls`/`role=tablist`+`tabpanel` با id؛ roving `tabindex`؛ کلیدهای ←/→/Home/End جابه‌جایی و فعال‌سازی تب.

## دروازه‌ی پذیرش
`node tools/minify.mjs` (بودجه‌ها: admin JS/CSS و widget ≤ سقف)؛ `php -l` همه؛ کل سوئیت‌ها + تست رگرسیون تازه‌ی C (قبل/بعد)؛ `pc-e2e.cjs` سبز؛ چک‌های تازه‌ی C (دکمه حین تولید disabled، اعمال دوم پیام موفقیت، timeout فارسی، ناوبری کیبوردی تب‌ها) در یک `dev-tests/pc-ux-e2e.cjs`؛ در پایان `restore-e2e`/`woo-*` هم سبز بمانند. بنچ به base برگردد.

## یافته‌های حاشیه‌ای C (برای BACKLOG/تصمیم)
- `pc-e2e-run.sh` روی بنچ یک محصول seedشده واقعی را با پاسخ ماک «اعمال» می‌کند و محتوایش را تغییر می‌دهد؛ این در `wp-env-setup.php` با اجرای دوباره پاک/بازسازی می‌شود (بی‌خطر، فقط haai-test).
