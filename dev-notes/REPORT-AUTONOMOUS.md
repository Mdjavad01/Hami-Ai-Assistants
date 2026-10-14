# گزارش اجرای خودکار (Autonomous Run)

> هر دسته پس از پایان اینجا اضافه می‌شود: فایل:خط، قبل/بعد، شواهد، «تست‌شد (چطور) / فقط خوانده شد / تست نشد».
> ادعای «تست‌شد» فقط برای چیزی که در اجرای واقعی یا مرورگر دیده شده.

## دسته ۰ — پیش‌نیاز و خط پایه (۲۰۲۶-۱۰-۱۳)

- **git**: وضعیت کاری تمیز شد؛ commit سند PROGRESS + tag `before-autonomous-run`. تگ‌های موجود پیش از اجرا: `after-batch-A`، `after-fix-e2e`.
- **mysqldump**: `C:\xampp\backups\haai-test-baseline-20261013-124839.sql` (≈۱۲۹۴ KB) — با `wp db export` (مسیر mysqldump از `C:\xampp\mysql\bin`).
- **خط پایه (قبل از هیچ تغییری)**:
  - `php -l` ریشه + templates + uninstall + ۳۶ فایل includes/modules → **۰ خطا** (تست‌شد: اجرای واقعی).
  - ۹ سوئیت stub (`tmp-checks*.php`) + `tmp-debug-check.php` → **۹۹۲ ok / ۰ fail** (تست‌شد: اجرای واقعی).
  - `diagnose()` واقعی روی بنچ (`wp eval`) → **۰ ایراد** (تست‌شد: اجرای واقعی).
  - ممیزی CSS (`tmp-css-audit.php`) → **۱ مشکل UNDEFINED**: `haai-pvedit__pane` در `class-haa-module-product-content.php` استفاده می‌شود ولی در admin.css تعریف ندارد (قاعده‌ی `[hidden]` در جلسه‌ی قبل حذف شد). ⇒ در BACKLOG ثبت شد؛ دامنه‌ی دسته C/I.
  - `pc-e2e.cjs` → **۳۵ ok / ۰ fail** (تست‌شد: Chromium واقعی، سه سناریو؛ بنچ بعدش بازگردانی شد).
- **بنچ**: ماژول‌ها `{chatbot, product-content}`، provider خالی، `db_version=7`؛ طبق دستور اجرا `allow_localhost=1` نگه داشته شد (تست‌ها به سوراخ is_url_safe تکیه نمی‌کنند).

## وضعیت ابزار کشف‌شده

- PHP CLI `C:\xampp\php\php.exe` = ۸.۲.۱۲ (فقط `php -l`؛ کدها باید ۷.۴-سازگار بمانند، ویژگی PHP 8 دستی ممنوع).
- WP-CLI `C:\xampp\wp-cli\wp-cli.phar`؛ بنچ `C:\xampp\htdocs\haai-test` (WP 7.1.3 + Woo 11.1.2، افزونه فعال).
- Playwright در `C:\Users\user\.workbuddy-ai\...\node_modules\playwright`؛ node ۲۲.۲۳.۲؛ git-bash در `C:\Program Files\Git\bin\bash.exe`.
- کاربر مرورگر تست: `haai_probe` (گذرواژه در `pc-e2e.cjs`؛ اینجا چاپ نمی‌شود). در دسته J حذف شود.

## دسته A — پایداری تنظیمات (snapshot + بازگردانی) — انجام شد (۲۰۲۶-۱۰-۱۳)

**فایل‌های تغییرکرده**
- `includes/class-haa-settings.php` — ثابت `SNAPSHOT_OPTION` (۲۱-۳۰)، `reset()` فراخوان `snapshot()` (۶۳۲)، توابع `snapshot()`/`has_snapshot()`/`snapshot_label()`/`restore_snapshot()` (۶۴۰-۷۲۴)، `import()` فراخوان `snapshot()` پس از اعتبارسنجی JSON (۱۹۲۹-۱۹۳۴).
- `includes/class-haa-admin.php` — ثبت هوک `admin_post_haai_restore` (۵۱)، متد `restore()` (۲۳۴-۲۵۸)، ورودی‌های notice برای `restored`/`restore-none` و تکمیل پیام `reset` (۲۵۸-۲۶۱)، پنل بازگردانی `render_restore_panel()` در تب پیشرفته (۶۳۱-۶۶۶).
- `uninstall.php:28` — پاک‌کردن `hami_ai_assistant_snapshot`.
- `assets/css/admin.css:617-621` — افزودن مودیفایر `.haai-panel--restore` (بازسازی `admin.min.css` با tools/minify.mjs).
- تست‌ها: `dev-tests/tmp-checks12.php` (۳۰ چک)، `dev-tests/restore-e2e.cjs` + `dev-tests/restore-e2e-run.sh`.

**قبل / بعد**
- قبل: هیچ نسخه‌ی پشتیبانی از reset/import نبود؛ `reset()` مقادیر را دور می‌ریخت و بازگشتی نبود. تست رگرسیون جدید روی کد قبل **fatal** می‌داد (ثابت/متد `SNAPSHOT_OPTION`/`snapshot()` وجود نداشت).
- بعد: `reset()`/`import()` یک نسخه‌ی تک‌نسخه‌ای از آپشن خام (autoload=no) با برچسب زمان می‌گیرند؛ بازگردانی در تب پیشرفته همه‌ی فیلدها + mirror ماژول‌ها را برمی‌گرداند.

**شواهد (تست‌شد)**
- `php -l` فایل‌های تغییریافته → سالم. کل سوئیت‌ها → ۱۰۲۵ ok / ۰ fail (شامل checks12 جدید).
- بنچ واقعی (wp eval): snapshot پس از reset=true، مقدار کلید در اسنپ‌شات سرنخ‌رمز (`haaienc:`)=true، بازگردانی کلید/مدل/ماژول=درست؛ `SELECT autoload` → `off`؛ `SELECT ... LIKE '%کلید%'` روی مقدار اسنپ‌شات → ۰ (نسخه‌ی رمزنشده ساخته نشده). `diagnose()` واقعی → ۰ ایراد.
- مرورگر (Playwright، `restore-e2e.cjs`): **۱۳ ok / ۰ fail** — پنل/دکمه/هدینگ فارسی/nonce دیده شد، رنگ پیش‌فرض #4f46e5 قبل و #abcdef بعد از بازگردانی، مدل برگشت، پیام موفقیت فارسی، بازگردانی دوباره idempotent.
- ممیزی CSS → همچنان ۱ مشکل (فقط BL-01 `haai-pvedit__pane`)؛ کلاس تازه‌ی `--restore` تعریف‌شده و به ممیزی اضافه نشد.

**گزارش save_all ناقص (تکلیف دسته A):** تنها فراخوان‌کننده‌ی تولیدی `save_all` خودِ `import()` (خط ۱۹۳۷) است که روی مقدار ذخیره‌شده ادغام می‌کند (ناقص نیست). سایر نوشتن‌های مستقیم `HAAI_OPTION` (`set_enabled_modules`:۵۱۶، `migrate_tone_typo`:۳۸۵، `site_profile::draft_approve`:۲۸۱، `install`:۳۵۵، migrator:۳۸) همگی آرایه‌ی کامل (خروجی `get()`) را می‌نویسند، نه بخش ناقص → هیچ مسیر «save_all ناقص» که فیلدها را پاک کند پیدا نشد. فرم از `save_section` استفاده می‌کند و migrator با `add_option`. ⇒ BL-03 بسته‌شد به‌عنوان «موردی یافت نشد».

## دسته B — وابستگی ووکامرس (M3 + M4) — انجام شد (۲۰۲۶-۱۰-۱۳)

**حقیقت وابستگی از روی کد (M4):**
- `product-content` → وابسته است (`requires_woocommerce` درست): درخت `product_cat` (class-haa-module-product-content.php:431،743،905،912)، خواندن محصول WC (:1585-1586)، فرمت قیمت `wc_price/wc_format_price_range` (:987،992). woo=true.
- `discount` → وابسته است: صدور کوپن واقعی `new WC_Coupon` (class-haa-module-discount.php:305)، پاک‌سازی کوپن (:511-512). woo=true.
- `leads` → **وابسته نیست**: فقط جدول‌های خودش (phone/email/note/event)، هیچ `wc_`/`WC_`/`haa_is_woo` ندارد (کلاس‌بدون‌تماس؛ خط ۳۵ قبلاً false بود). woo=false.
- منبع حقیقت **یکی** شد: `Haa_Modules::catalog_meta()['woo']`. ثابت `Haa_Modules::requires_woocommerce($slug)` خواننده‌ی یکتا؛ `load()`، `describe_all()` و هر ۱۱ متد نمونه به آن واگذار می‌کنند → هیچ واگرایی ممکن نیست.

**فایل‌های تغییرکرده**
- `includes/class-haa-modules.php` — ثابت `requires_woocommerce()` (۱۳۶-۱۵۲)، گیت `load()` برای خفتگی بدون ووکامرس پیش از `class_exists()` (۱۸۵-۱۹۴)، `describe_all()` از منبع واحد (۲۱۲-۲۲۴).
- `includes/class-haa-admin-modules.php` — `save()` دیگر فلگ «روشن» ماژول ووکامرسیِ غیرفعال‌شونده را پاک نمی‌کند (۳۵-۴۹).
- `modules/class-haa-module-*.php` (۱۱ فایل) — بدنه‌ی `requires_woocommerce()` → `Haa_Modules::requires_woocommerce( $this->slug() )`.
- تست‌ها: `tmp-checks13.php` (۶۹ چک)، `woo-off-check.sh`، `woo-modules-e2e.cjs` + `woo-modules-e2e-run.sh`.

**قبل / بعد**
- قبل: `load()` ووکامرسی‌ها را بدون ووکامرس هم لود/هوک می‌کرد؛ `requires_woocommerce()` نمونه‌ها منبع دومِ موازیِ `catalog_meta` بود؛ ذخیره‌ی ماژول‌ها با ووکامرس خاموش، فلگ «روشن» product-content/discount را پاک می‌کرد.
- بعد: خفتگی کامل (فایل/نمونه/هوک نه)، فلگ ذخیره‌شده می‌ماند و با برگشت ووکامرس خودبه‌خود فعال می‌شود؛ پیام/کلید غیرفعال در تب ماژول‌ها؛ منبع واحد.

**شواهد (تست‌شد، واقعی/مرورگر)**
- `php -l` همه سالم. کل سوئیت‌ها ۱۰۹۴ ok/۰ fail؛ tmp-checks13 قبل **fatal** (متد نبود) بعد ۰ fail.
- `woo-off-check.sh` روی بنچ (Woo خاموش، ۱۱ ماژول روشن): product-content/discount در `loaded()` نیستند؛ chatbot و ۸ تا لود می‌شوند؛ فلگ‌ها پاک نشده (count=11)؛ describe_all: missing=true/available=false؛ wp-admin، فرانت، REST /token و کران بدون fatal؛ با فعال‌کردن دوباره‌ی Woo هر دو خودبه‌خود برگشتند.
- HAAI deactivate/activate با Woo خاموش → بدون fatal (front status=200، بدون «Fatal error»).
- `woo-modules-e2e.cjs` (مرورگر): **۱۵ ok/۰ fail** — بَج «نیازمند ووکامرس» + سوئیچ disabled برای pc/discount، article-writer فعال؛ after save: article-writer خاموش شد ولی pc/discount «روشن» ماندند (تأیید رفع save-preserve). بنچ به base برگشت.
- uninstall (فقط خوانده شد): هیچ ارجاع ووکامرس ندارد؛ با Woo خاموش fatal نمی‌دهد (کوئری جدول/option/کران/مدیا).
- ممیزی CSS همچنان ۱ (فقط BL-01)؛ diagnose واقعی ۰.

## دسته C1 — قفل تولید (P3)، پیام‌های خطا (P5)، اعمال idempotent (P4) — انجام شد (۲۰۲۶-۱۰-۱۳، جلسه‌ی دوم)

**فایل‌های تغییرکرده**
- `modules/class-haa-module-product-content.php` — ثابت‌های `GEN_LOCK`/`GEN_LOCK_TTL`/`APPLIED`/`APPLIED_TTL` (۱۹-۳۷)، `handle_generate()` قفل می‌گیرد و آزاد می‌کند (۱۳۲۷-۱۳۷۲)، شیم‌های `generate_locked/acquire/release_generate_lock` + `mark_applied/applied_recently` (۱۳۷۴-۱۴۴۴)، `handle_apply()` پاسخ «همین حالا اعمال شده» به‌جای خطای قرمز (۱۶۹۴-۱۷۰۸) و `mark_applied()` پس از اعمال (۱۷۳۰)، رشته‌های i18n جدید `generating`/`applying` (۱۲۳-۱۲۴).
- `includes/class-haa-api.php` — `map_transport_error()` با شاخه‌ی تازه‌ی `haai_proxy` و یکسان‌سازی املای «پراکسی» (۱۰۸۴-۱۱۲۳)، `map_http_error()` با حالت‌های ۵۰۴ و ۵۰۲/۵۰۳ (۱۱۴۱-۱۱۵۵)، پیام پاسخ خالی/بی‌ساختار + راهنما (۵۰۴).
- `assets/js/product-picker.js` (+ `product-picker.min.js` بازسازی‌شده) — `formByAction()`/`guardSubmit()`: غیرفعال‌کردن دکمه و متن «در حال ساخت…/در حال اعمال…» (۵۶۴-۶۰۴).
- تست‌ها: `tmp-checks14.php` (۲۰ چک)، `pc-gen-e2e.cjs` + `pc-gen-e2e-run.sh`، `p5-errors-check.sh`؛ `mock-api.php` با حالت‌های slow/timeout/504/503/empty.

**قبل / بعد (رفتار غلط واقعی، نه fatal)**
- `p5-errors-check.sh` روی بنچ واقعی با ماک: **قبل ۸ از ۱۲ رد** ← ۵۰۴ و ۵۰۳ پیام عمومی «از دسترس خارج است» بدون ذکر وضعیت و بدون «دوباره بزنید»؛ پاسخ خالی بدون راهنما؛ **خطای پراکسی اشتباه تشخیص داده می‌شد** (روی این بنچ پیام «مهلت اتصال تمام شد» یا «مسدود بودن سرویس» می‌داد درحالی‌که مشکل از پراکسی بود). **بعد: ۱۲/۱۲ ok، exit 0** (پیام اختصاصی `haai_proxy` + راهنمای بررسی آدرس/پورت پراکسی).
- `pc-gen-e2e.cjs` با همان فایل تست، قبل/بعد کد: **قبل ۵ ok / ۹ fail** — دکمه حین اجرا فعال می‌ماند؛ **دو درخواست هم‌زمان = ۲ تماس واقعی به مدل** (calls=2، یعنی پرداخت دوباره)؛ «اعمال» دوباره خطای قرمز «پیش‌نمایشی برای اعمال نیست» می‌داد. **بعد ۱۴ ok / ۰ fail** — دکمه disabled + «در حال ساخت…»، یک تماس مدل، رفض با پیام فارسی «در حال انجام است… دوباره بزنید»، اعمال دوم پیام سبز «همین حالا اعمال شده است».
- `tmp-checks14.php`: رفع اینجا افزودن مکانیزم تازه است (قفل/مارکر)، بنابراین مطابق قاعده‌ی اثبات، شاهد رفتار غلط واقعی در دو مورد بالا است و این سوئیت فقط معنای شیم‌ها را قفل می‌کند (قبل: متد/ثابت تعریف‌نشده).

**دروازه‌ی پذیرش**
- `php -l` همه‌ی فایل‌های ship + mock + سوئیت جدید → ۰ خطا. سوئیت‌ها: ۱۰۹۴ → **۱۱۱۴ ok / ۰ fail**. `diagnose()` واقعی → ۰. `pc-e2e.cjs` (بدون تغییر) → **۳۵ ok / ۰ fail** با گارد‌های تازه.
- اندازه‌ها پس از `node tools/minify.mjs`: product-picker.min.js ۵٬۵۳۸ B min+gzip (صفحه‌ی ادمین)؛ بودجه‌ی ویجت دست‌نخورده: JS ۴٬۰۱۷ B، CSS ۲٬۲۷۲ B.
- بنچ به پایه برگشت: provider خالی، allow_localhost=1، ماژول‌ها {chatbot, product-content}؛ بدون transient باقی‌مانده‌ی قفل/مارکر/پیش‌نمایش.

**نکات/بازگشت‌پذیری**
- کد خطای `haai_dns` عمداً حفظ شد و به فهرست خطاهای قابل‌تلاش اضافه نشد → رفتار retry تغییر نکرد؛ `haai_proxy` هم قابل‌تلاش نیست (مثل DNS).
- املای «پروکسی» به «پراکسی» در رشته‌های همان دو تابع یکدست شد (باقی‌مانده‌ی سراسری: ۰).
- یافته‌ی تست‌نویسی (برای دسته‌های بعدی): خواندن DOM در میانه‌ی یک submit واقعی باعث می‌شود Playwright تا اتمام ناوبری صبر کند و هم‌پوشانی درخواست‌ها از بین برود؛ برای A از listener لغو‌کننده و برای B از `click({noWaitAfter:true})` استفاده شد.
- **تست‌نشده:** هیچ سرویس AI واقعی (فقط ماک محلی). مسیر `run_content_job` (صف) عمداً بدون قفل مانده است — قفل فقط کلیک تک‌محصولی را می‌پوشاند، چون صف خودش کران‌دار و سریال است. (این مورد در BL-06 ثبت شد.)

## دسته A2 — UI خروجی/ورودی/بازنشانی در تب پیشرفته (رفع BL-04) — انجام شد (۲۰۲۶-۱۰-۱۳، جلسه‌ی سوم؛ گزارشش پس از C2 نوشته شده)

**پیش‌نیاز**
- mysqldump تازه: `C:\xampp\backups\haai-test-pre-A2-20261013-181235.sql` (≈۱۳۲۰ KB). sks دست‌نخورده.
- بررسی پایه پیش از شروع: git تمیز + tag `after-autobatch-B/C2`، php -l ۰، سوئیت ۱۱۱۴/۰، diagnose ۰، ممیزی CSS ۰، و هر پنج دروازه‌ی مرورگر سبز (pc-e2e ۳۵، pc-gen ۱۴، pc-ux ۳۴، restore ۱۳، woo-modules ۱۵).

**فایل‌های تغییرکرده**
- `includes/class-haa-settings.php` — `IMPORT_MAX_BYTES`(۵۱۲KB)/`RESET_WORD` (۲۳-۳۴)، `validate_import()` (≈۱۹۱۸-۲۰۳۳) و `import( $json, $args )` با `allow_secrets` (≈۲۰۳۵-۲۱۰۰).
- `includes/class-haa-admin.php` — ثابت‌های `PENDING_OPTION/PENDING_TTL`، ثبت هوک `admin_post_haai_import_confirm`، `import_preview()`/`import_confirm()` (دو مرحله، توکن یک‌بارمصرف + `hash_equals`)، `reset()` با واژه‌ی تایپ‌شده، `redirect()` با آرگومان اضافه، نقشه‌ی اعلان‌ها (۱۴ وضعیت فارسی)، پنل `render_settings_tools()` + حفظ `render_restore_panel()`.
- `assets/js/admin.js` (+min) — فقط unlock شدن دکمه‌ی بازنشانی با واژه‌ی درست (سرور دوباره بررسی می‌کند).
- `assets/css/admin.css` (+min) — `.haai-panel--tools` و `.haai-panel--inner` (ممیزی CSS همچنان ۰).
- `uninstall.php:130` — پاک‌کردن transient نیم‌کاره‌ی `haai_import_pending`.
- تست‌ها: `tmp-checks15.php` (۲۶ چک)، `settings-tools-e2e.cjs` + رانر، `guard-check.sh` (ماتریس پذیرش/رد).

**قبل / بعد (رفتار غلط واقعی در تست قبل)**
- `tmp-checks15.php` روی کد قبلی: **۷ خطای واقعی** — کلید ناشناخته‌ی `zzz_injected` و بخش `notasection` **درون آپشن ذخیره شدند**؛ `provider.timeout=999999` بدون سقف ذخیره شد؛ `model` بدون sanitize ذخیره شد؛ و **کلید API فایل جای کلید زنده را نشست** (بدون هیچ تأیید) آن‌هم **به‌صورت متن روشن در دیتابیس**. بعد از رفع: ۲۶/۲۶ سبز.
- `settings-tools-e2e.cjs`: **۳۳ ok / ۰ fail** — export واقعاً دانلود شد و `api_key`/`proxy_password` خالی و بدون هیچ رشته‌ی شبیه `sk-` بود؛ بازنشانی با واژه‌ی غلط/بدون واژه رد شد و مقدار تغییر نکرد، با واژه‌ی درست اجرا شد و پنل بازگردانی ظاهر و مقدار را برگرداند؛ واردات در دو مرحله با خلاصه‌ی «N کلید معتبر / M تفاوت» + هشدار کلید انجام شد، بدون تیک «جایگزینی کلیدها» رد شد (`import-need-keys`)، با تیک اعمال شد، و `restore` کل واردات را عقب زد؛ فایل غیرJSON → `import-failed` و فایل ۷۰۰KB → `import-too-big`؛ POST بدون nonce → ۴۰۳ و تنظیمات دست‌نخورده.
- `guard-check.sh` روی WP واقعی: **۷/۷** — A ادمین بدون nonce رد، B کاربر subscriber با **nonce معتبرِ خودش** (`nonce_valid=true`, `can_manage=false`) رد شد و هیچ نوشتنی رخ نداد (شاخه‌ی capability عملاً اجرا شد)، C ادمین با nonce درست پذیرفته شد.

**یافته/رفع حین تست**
- باگ واقعی که تست مرورگر گرفت: فراخوان `render_restore_panel()` هنگام افزودن پنل ابزارها **جایش را از دست داد** (پنل بازگردانی ناپدید شد) → «a snapshot panel appeared for the undo» قرمز شد؛ هر دو پنل اکنون فراخوان می‌شوند. (پیش از رفع: fail، پس از رفع: ok.)
- دو باگ در خودِ تست (نه کد): ناوبری مجدد بعد از پیش‌نمایش، توکن تأیید را از URL می‌انداخت؛ و لاگین subscriber در harness کامل نمی‌شد → ماتریس دسترسی به `guard-check.sh` (اجرای واقعی همان گارد) منتقل شد.

**دروازه‌ی پذیرش (پایان دسته)**
- `php -l` ۳۸ فایل ship → ۰؛ سوئیت‌ها ۱۱۱۴ → **۱۱۴۰ ok / ۰ fail**؛ ممیزی CSS → **۰**؛ `diagnose()` واقعی → ۰.
- مرورگر دوباره اجرا و سبز: `pc-e2e` **۳۵/۳۵**، `restore-e2e` **۱۳/۱۳** + `settings-tools-e2e` **۳۳/۳۳**.
- اندازه‌ها (min+gzip): admin.min.css ۶٬۴۶۶ B، admin.min.js ۴٬۴۸۰ B، product-picker.min.js ۶٬۲۸۷ B؛ ویجت بدون تغییر: JS ۴٬۰۱۷ / CSS ۲٬۲۷۲ (زیر سقف AGENTS).
- **تست‌نشده:** هیچ سرویس AI واقعی (این دسته اصلاً به مدل نیاز نداشت).

## وضعیت نشست سوم (پایان این نقطه)
- انجام‌شده: پایه‌سنجی کامل، **A2** (tag `after-autobatch-A2`)، **G1** (tag `after-autobatch-G1`)، ثبت تصمیم‌های BL-05→G3 / BL-06 / BL-02 + یافته‌ی تازه BL-07.
- باقی‌مانده: G2، G3 (با BL-05)، D، F، H، I، J. **E شروع نشد (تصمیم کاربر).** sks قفل؛ بنچ روی پایه + allow_localhost=1.

## دسته G1 — بستن سوراخ SSRF در `is_url_safe` — انجام شد (۲۰۲۶-۱۰-۱۳، جلسه‌ی سوم)

**فایل‌های تغییرکرده**
- `includes/class-haa-api.php` — بازنویسی `is_url_safe()` (≈۹۸۰-۱۰۸۰): حذف براکت IPv6 پیش از هر بررسی، strip zone-id (`%eth0`)، کلید کش شامل `allow_localhost`، مسیر نام→`resolve_host()` (A + AAAA، رد در صورت خصوصی بودن هر پاسخ)، مسیر IP→`judge_ip()`. توابع تازه: `host_is_name`، `is_loopback_name`، `resolve_host`، `decode_numeric_host` (دسیمال/هگز/اکتال)، `judge_ip`، `ip_is_always_forbidden`، `ip_is_private`، `ip_bytes` (با fallback بدون `inet_pton`)، `bytes_to_ipv4`، `ipv4_mapped`. `is_public_ip()` قدیمی حذف و جایش `judge_ip()` آمد (هیچ مصرف‌کننده‌ی دیگری نداشت).
- `dev-tests/g1-ssrf-check.sh` — ماتریس ۲۶ حالته روی WP واقعی (دو پرچم allow_localhost).
- `dev-tests/tmp-wp-stubs.php` — آینه‌ی استاب هم‌راستا شد (براکت، `::ffff:`، اشکال عددی، `fe80/fc/fd`) و `mirror_matches_real_guard()` به مارکرهای پیاده‌سازی تازه به‌روز شد (سخت‌گیرانه‌تر: ۷ مارکر به‌جای ۴؛ تست سست نشد).
- `dev-tests/tmp-checks7.php` — ۸ حالت تازه‌ی ردشدن به فهرست SSRF اضافه شد ( `[::1]`، `::ffff:127.0.0.1`، `fe80::1`، `fc00::1`، دسیمال، هگز، اکتال، `::ffff:169.254.169.254` ).

**قبل / بعد (رفتار واقعی)**
- قبل (`g1-ssrf-check.sh` روی کد قبلی): **۱۰ رد گم‌شده** — با allow_localhost=0 میزبان‌های `[::1]`، `[::ffff:127.0.0.1]`، `[fe80::1]`، `[fc00::1]`، `2130706433`، `0x7f000001`، `0177.0.0.1` **accept** می‌شدند (به‌دلیل براکت و مسیر «DNS نشد → اجازه»)، و با allow_localhost=1 هم `0.0.0.0`، `[fe80::1]`، `[::ffff:169.254.169.254]` accept می‌شدند.
- بعد: **۲۶/۲۶ مطابق جدول انتظار** (failures=0). IPهای عمومی (`8.8.8.8`, `93.184.216.34`) در هر دو حالت آزادند؛ متادیتا/`0/8`/`fe80::/10` حتی با پرچم روشن رد می‌شوند.

**دروازه‌ی پذیرش**
- مسیر توسعه باید می‌مانست: `pc-e2e` **۳۵/۳۵** و `pc-gen-e2e` **۱۴/۱۴** با mock روی `http://[::1]:8802` و allow_localhost=1 (بدون تکیه بر سوراخ) و `p5-errors-check.sh` **۱۲/۱۲**.
- `php -l` ۰؛ سوئیت‌ها ۱۱۴۰ → **۱۱۴۸ ok / ۰ fail**؛ ممیزی CSS ۰؛ `diagnose()` واقعی ۰؛ بنچ به پایه (provider خالی، allow_localhost=1، ماژول‌های پایه).
- یافته‌ی حاشیه‌ای: `is_keyless()` براکت IPv6 را نمی‌شناسد ⇒ **BL-07** (رفع نکردم؛ خارج از دامنه G1).
- **تست‌نشده:** حمله‌ی واقعی DNS-rebinding با AAAA (سند بنچ IPv6-only قابل اعتماد نیست)؛ منطق resolve برای میزبان‌های عمومی با DNS واقعی بیرون از بنچ.
## دسته C2 — UX باقی‌مانده‌ی محتوای محصول (P2، P6، P7، P8، P9) + صفرکردن ممیزی CSS (BL-01) — انجام شد

**فایل‌های تغییرکرده**
- `modules/class-haa-module-product-content.php`
  - P6: pane نمایش با پوشش `data-haai-pvbody` + بلوک FAQ برچسب‌دار که با همان `Haa_Seo::faq_html()` رندر می‌شود (≈۳۳۳-۳۵۲).
  - P9: تب‌ها `id` + `aria-controls` + `tabindex`؛ paneها `role=tabpanel` + `aria-labelledby`؛ tablist با `aria-label` (≈۳۳۴-۳۴۶).
  - P8: `aria-live="polite"` (+`role=status`) روی شمارنده صفحه، مجموع نتایج و شمارنده انتخاب (≈۱۱۵۱، ۱۱۸۲، ۱۱۹۲).
  - P2: `data-haai-dirty-confirm` روی فرم «تولید» و «حذف پیش‌نمایش» (≈۲۳۵، ۴۰۰)؛ رشته‌های i18n `discard`/`leavePage`/`faqHeading` (≈۱۲۵-۱۲۷).
- `assets/js/product-picker.js` (+min): `paintFaq()` (آینه‌ی `faq_html` با textContent، بدون درج HTML)، `selectTab()` + ناوبری کیبوردی، ردیابی dirty + `beforeunload` + تأیید، `focusKey()/restoreFocus()`، بَج کوتاه «ناقص» + `title`/`aria-label` (P7 ≈۲۵۶-۲۶۹؛ P8 ≈۳۷۸-۴۱۴؛ P9/P6 ≈۵۰۵-۵۸۰؛ P2 ≈۶۰۴-۶۴۵).
- `assets/css/admin.css` (+min): `.haai-pvedit__pane{min-width:0}` (جلوگیری از سرریز افقی وقتی HTML مدل یک URL بلند دارد) و `.haai-pv-faq`/`.haai-pv-faq__label` ⇒ **رفع BL-01**.
- تست‌ها: `pc-ux-e2e.cjs` + `pc-ux-e2e-run.sh` (۳۴ چک)، `tmp-probe-focus.cjs` (عیب‌یابی).

**قبل / بعد (رفتار واقعی در مرورگر)**
- قبل (`git stash` کد C2، همان فایل تست): **۱۷ رفتار غلط** — متن بَج لیست بلند بدون tooltip؛ `aria-live` = null؛ فوکوس بعد از رندر AJAX به `<body>` می‌افتاد؛ هیچ بلوک FAQ در پیش‌نمایش نبود (پیش‌نمایش ≠ خروجی نهایی)؛ کلیدهای ←/→/Home/End کار نمی‌کردند، `aria-controls`/`role=tabpanel` نداشتیم؛ ویرایش دستی هیچ هشدار ترک صفحه‌ای نمی‌داد و «حذف پیش‌نمایش» بدون تأیید کار را خراب می‌کرد.
- بعد: **۳۴ ok / ۰ fail** (exit 0). شاهد P6 سرتاسری: پیش‌نمایش فقط یک سؤالِ تایپ‌شده را نشان داد و پس از «اعمال»، بدنه‌ی محصول دقیقاً همان یک سؤال را داشت و سؤالات مدل حذف شده بودند.

**دروازه‌ی پذیرش**
- `php -l` → ۰؛ سوئیت‌ها → ۱۱۱۴ ok/۰ fail؛ **ممیزی CSS → PROBLEMS: 0** (اولین بار در این اجرا)؛ `diagnose()` واقعی → ۰.
- دروازه‌های مرورگری قبلی بدون تغییر سبزند: `pc-e2e` **۳۵/۳۵** و `pc-gen-e2e` (C1) **۱۴/۱۴**؛ `restore-e2e` و `woo-modules` در ابتدا و انتهای دسته‌ها سبز بودند.
- اندازه‌ها (min+gzip): admin.min.css ۶٬۴۴۲ B، product-picker.min.js ۶٬۲۸۷ B (صفحه ادمین)، ویجت دست‌نخورده: JS ۴٬۰۱۷ / CSS ۲٬۲۷۲.
- بنچ به پایه: provider خالی، allow_localhost=1، ماژول‌ها {chatbot, product-content}، بدون transient باقی‌مانده.

**تصمیم‌ها**: در DECISIONS.md (بخش C2) — آینه‌ی FAQ با textContent (ضد XSS)، بلوک پنهان وقتی جفت معتبری نیست، dirty فقط با ویرایش واقعی، فوکوس با شناسه محصول بازگردانده می‌شود، ArrowRight طبق ترتیب DOM (بازآینه‌ی RTL عملاً برعکس نشد).
