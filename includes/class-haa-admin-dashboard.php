<?php
/**
 * Dashboard: health at a glance, today's spend, queue state, KB index size.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Admin_Dashboard {

        /** @return void */
        public static function render() {
                $usage   = Haa_Usage::summary( 7 );
                $today   = Haa_Usage::today();

                // Cards below read these three keys; summary() only covers the window.
                $usage['messages_today'] = $today['messages'];
                $usage['tokens_today']   = $today['tokens'];
                $usage['tokens_7d']      = (int) $usage['tokens'];

                $queue   = Haa_Queue::stats();
                $search  = Haa_Search::stats();
                $crawl   = Haa_Crawl::stats();
                // 2.1.16 — a running crawl with a stale heartbeat advances ONE
                // batch inline right here: even with broken/blocked JS every
                // dashboard load makes honest progress, and the load right after
                // «شروع» already shows real numbers. Safe next to a healthy
                // ticker (the batch lock serialises both drivers); no-ops when
                // nothing is running.
                $crawl_state = Haa_Crawl::auto_drive();
                $health  = self::health();
                $modules = Haa_Modules::describe_all();
                ?>
                <div class="wrap haai-wrap">
                        <?php Haa_Admin::page_header( __( 'داشبورد دستیار هوشمند', 'hami-ai-assistant' ) ); ?>

                        <h2 class="haai-sec-title"><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></h2>
                        <div class="haai-cards">
                                <?php foreach ( $health as $card ) : ?>
                                        <?php $tone = isset( $card['tone'] ) ? $card['tone'] : ''; ?>
                                        <div class="haai-card haai-card--health<?php echo '' !== $tone ? ' haai-card--' . esc_attr( $tone ) : ''; ?>">
                                                <div class="haai-card__value">
                                                        <span class="haai-dot<?php echo '' !== $tone ? ' haai-dot--' . esc_attr( $tone ) : ''; ?>" aria-hidden="true"></span>
                                                        <?php echo esc_html( $card['value'] ); ?>
                                                </div>
                                                <div class="haai-card__label"><?php echo esc_html( $card['label'] ); ?></div>
                                                <?php if ( ! empty( $card['hint'] ) ) : ?>
                                                        <div class="haai-card__hint"><?php echo esc_html( $card['hint'] ); ?></div>
                                                <?php endif; ?>
                                        </div>
                                <?php endforeach; ?>
                        </div>

                        <h2 class="haai-sec-title"><?php esc_html_e( 'مصرف و کارها', 'hami-ai-assistant' ); ?></h2>
                        <div class="haai-cards">
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $usage['messages_today'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'پیام امروز', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $usage['tokens_today'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'توکن امروز', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $usage['tokens_7d'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'توکن ۷ روز اخیر', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card<?php echo empty( $queue['failed'] ) ? '' : ' haai-card--danger'; ?>">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $queue['pending'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'کارهای در صف', 'hami-ai-assistant' ); ?></div>
                                        <?php if ( ! empty( $queue['failed'] ) ) : ?>
                                                <div class="haai-card__hint">
                                                        <?php
                                                        printf(
                                                                /* translators: %s: number of failed jobs. */
                                                                esc_html__( '%s مورد ناموفق', 'hami-ai-assistant' ),
                                                                esc_html( number_format_i18n( (int) $queue['failed'] ) )
                                                        );
                                                        ?>
                                                </div>
                                        <?php endif; ?>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $search['chunks'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'بخش‌های ایندکس‌شده', 'hami-ai-assistant' ); ?></div>
                                        <?php if ( ! empty( $search['vectors'] ) ) : ?>
                                                <div class="haai-card__hint"><?php echo esc_html( sprintf( '%s بردار', number_format_i18n( (int) $search['vectors'] ) ) ); ?></div>
                                        <?php endif; ?>
                                </div>
                        </div>

                        <h2 class="haai-sec-title">
                                <?php esc_html_e( 'دانش سایت', 'hami-ai-assistant' ); ?>
                                <span class="haai-badge"><?php echo esc_html( sprintf( '%s صفحه / %s تکه', number_format_i18n( (int) $crawl['pages'] ), number_format_i18n( (int) $crawl['chunks'] ) ) ); ?></span>
                        </h2>
                        <p class="description">
                                <?php esc_html_e( 'خزش، محتوای رندرشدهٔ صفحات سایت (منو، فوتر، ویجت‌ها و صفحه تماس) را به پایگاه دانش اضافه می‌کند و شماره تماس، اینستاگرام و لینک صفحه تماس را یک‌جا برای دستیار خلاصه می‌کند. آدرس‌های دلخواه را در تب «دانش سایت» بدهید.', 'hami-ai-assistant' ); ?>
                        </p>
                        <?php
                        // Scope preview — the «اولویت‌بندی / انتخاب صفحه» control sits in
                        // the «دانش سایت» tab, but the admin must see the SCALE of what
                        // is about to be queued right next to the button.
                        $crawl_cfg    = Haa_Crawl::config();
                        $crawl_counts = Haa_Crawl::type_counts();
                        $crawl_queue  = count( Haa_Crawl::targets() );
                        $on  = array();
                        $off = array();
                        $type_labels = array( 'page' => 'برگه‌ها', 'product' => 'محصولات', 'post' => 'نوشته‌ها' );
                        foreach ( $type_labels as $slug => $label ) {
                                $n = (int) $crawl_counts[ $slug ];
                                if ( in_array( $slug, (array) $crawl_cfg['types'], true ) ) {
                                        $on[] = $label . ' (' . number_format_i18n( $n ) . ')';
                                } elseif ( 'product' !== $slug || haa_is_woo() ) {
                                        $off[] = $label . ' (' . number_format_i18n( $n ) . ')';
                                }
                        }
                        $eta_min = (int) max( 1, ceil( $crawl_queue * 3 / 60 ) );
                        ?>
                        <p class="description" data-haai-crawl-scope>
                                <?php
                                echo esc_html( sprintf(
                                        /* translators: 1: queue size, 2: minutes estimate, 3: on-types, 4: custom URL count, 5: off-types. */
                                        __( 'در صف خزش: %1$s صفحه (حدود %2$s دقیقه) — به‌ترتیب اولویت: صفحه‌ی اول، %4$s آدرس دلخواه، سپس %3$s.%6$s', 'hami-ai-assistant' ),
                                        number_format_i18n( $crawl_queue ),
                                        number_format_i18n( $eta_min ),
                                        implode( '، ', $on ) ? implode( '، ', $on ) : 'هیچ نوع محتوایی',
                                        number_format_i18n( count( (array) $crawl_cfg['urls'] ) ),
                                        '',
                                        $off ? ' خارج از خزش: ' . implode( '، ', $off ) . ' (تغییر از تب «دانش سایت»).' : ''
                                ) );
                                ?>
                        </p>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-actions" data-haai-crawl-form>
                                <?php wp_nonce_field( 'haai_admin' ); ?>
                                <input type="hidden" name="action" value="haai_crawl_start">
                                <button type="submit" class="button button-primary" data-haai-crawl-start>
                                        <?php echo empty( $crawl_state['running'] ) ? esc_html__( 'کرال و به‌روزرسانی دانش سایت', 'hami-ai-assistant' ) : esc_html__( 'ادامهِ خزش', 'hami-ai-assistant' ); ?>
                                </button>
                                <?php if ( ! empty( $crawl_state['running'] ) ) : ?>
                                        <button type="button" class="button" data-haai-crawl-stop><?php esc_html_e( 'توقف خزش', 'hami-ai-assistant' ); ?></button>
                                        <button type="submit" class="button" name="haai_crawl_reset" value="1" onclick="return confirm('از نو شروع شود؟ پیشرفت فعلی صفر می‌شود.');"><?php esc_html_e( 'شروع از نو', 'hami-ai-assistant' ); ?></button>
                                <?php endif; ?>
                                <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Haa_Admin::PAGE, 'tab' => 'knowledge' ), admin_url( 'admin.php' ) ) ); ?>">
                                        <?php esc_html_e( 'انتخاب صفحات و نوع‌ها', 'hami-ai-assistant' ); ?>
                                </a>
                        </form>
                        <div class="haai-progress" data-haai-crawl-progress<?php echo empty( $crawl_state['running'] ) ? ' hidden' : ''; ?>>
                                <div class="haai-progress__bar" data-haai-crawl-bar style="width:<?php echo (int) $crawl_state['percent']; ?>%"></div>
                        </div>
                        <p class="description" data-haai-crawl-status>
                                <?php
                                if ( ! empty( $crawl_state['running'] ) ) {
                                        esc_html_e( 'خزش در حال اجراست…', 'hami-ai-assistant' );
                                } elseif ( ! empty( $crawl_state['finished_at'] ) ) {
                                        echo esc_html( sprintf( 'آخرین خزش: %s', mysql2date( 'Y/m/d H:i', gmdate( 'Y-m-d H:i:s', (int) $crawl_state['finished_at'] ) ) ) );
                                } else {
                                        esc_html_e( 'هنوز خزشی اجرا نشده است.', 'hami-ai-assistant' );
                                }
                                ?>
                        </p>

                        <h2 class="haai-sec-title">
                                <?php esc_html_e( 'ماژول‌ها', 'hami-ai-assistant' ); ?>
                                <span class="haai-badge">
                                        <?php
                                        $on_count = count( array_filter( $modules, fn( $m ) => ! empty( $m['enabled'] ) ) );
                                        echo esc_html(
                                                sprintf(
                                                        /* translators: 1: enabled module count, 2: total module count. */
                                                        __( '%1$d از %2$d', 'hami-ai-assistant' ),
                                                        $on_count,
                                                        count( $modules )
                                                )
                                        );
                                        ?>
                                </span>
                        </h2>
                        <div class="haai-mod-grid">
                                <?php foreach ( $modules as $m ) : ?>
                                        <?php
                                        $on      = ! empty( $m['enabled'] );
                                        $blocked = ! empty( $m['missing_woocommerce'] );
                                        ?>
                                        <article class="haai-mod-card haai-mod-card--static<?php echo $on ? ' is-on' : ''; ?><?php echo $blocked ? ' is-blocked' : ''; ?>">
                                                <div class="haai-mod-card__top">
                                                        <span class="haai-mod-card__icon"><?php echo Haa_Admin::module_icon( $m['slug'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                                                        <div class="haai-mod-card__names">
                                                                <h3 class="haai-mod-card__title"><?php echo esc_html( $m['label'] ); ?></h3>
                                                                <code class="haai-slug"><?php echo esc_html( $m['slug'] ); ?></code>
                                                        </div>
                                                </div>
                                                <div class="haai-mod-card__foot">
                                                        <?php if ( $blocked ) : ?>
                                                                <span class="haai-badge haai-badge--warn"><?php esc_html_e( 'نیازمند ووکامرس', 'hami-ai-assistant' ); ?></span>
                                                        <?php elseif ( $on ) : ?>
                                                                <span class="haai-badge haai-badge--on"><?php esc_html_e( 'روشن', 'hami-ai-assistant' ); ?></span>
                                                        <?php else : ?>
                                                                <span class="haai-badge"><?php esc_html_e( 'خاموش', 'hami-ai-assistant' ); ?></span>
                                                        <?php endif; ?>
                                                </div>
                                        </article>
                                <?php endforeach; ?>
                        </div>

                        <p class="haai-actions">
                                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Haa_Admin::PAGE ) ); ?>">
                                        <?php esc_html_e( 'تنظیمات', 'hami-ai-assistant' ); ?>
                                </a>
                                <a class="button" href="<?php echo esc_url( Haa_Util::screen_url( 'modules' ) ); ?>">
                                        <?php esc_html_e( 'مدیریت ماژول‌ها', 'hami-ai-assistant' ); ?>
                                </a>
                        </p>

                        <?php self::render_diagnostics(); ?>
                </div>
                        <?php
                        // Dashboard crawl ticker: the classic POST above starts (or
                        // RESUMES) the cursor, this script drives the remaining batches
                        // over AJAX. 2.1.15 hardening for heavy sites:
                        //   - never silent: percent, page count, the URL being read
                        //     right now and a live ETA between batches;
                        //   - never frozen: server/network hiccups are retried with
                        //     backoff instead of dying on the first bad response;
                        //   - resumable: stop keeps the cursor, resume continues from
                        //     it, and restart is an explicit reset button.
                        if ( ! empty( $crawl_state['running'] ) ) : ?>
                        <script>
                        (function () {
                                if (!window.haaiAdmin) return;
                                var bar = document.querySelector('[data-haai-crawl-bar]');
                                var wrap = document.querySelector('[data-haai-crawl-progress]');
                                var status = document.querySelector('[data-haai-crawl-status]');
                                var startBtn = document.querySelector('[data-haai-crawl-start]');
                                var stopBtn = document.querySelector('[data-haai-crawl-stop]');
                                var running = true, retries = 0;
                                var MAX_RETRY = 6;

                                function say(t) { if (status) status.textContent = t; }
                                function fmtETA(s) {
                                        s = parseInt(s || 0, 10);
                                        if (s <= 0) return '';
                                        if (s < 90) return '~' + s + ' ثانیه';
                                        var m = Math.round(s / 60);
                                        if (m < 90) return '~' + m + ' دقیقه';
                                        return 'بیش از یک ساعت';
                                }
                                function short(u) { return (u || '').replace(/^https?:\/\//, '').replace(/\/$/, ''); }
                                function describe(p) {
                                        return 'خزش: ' + p.percent + '% (' + p.offset + ' از ' + p.total + ' صفحه)'
                                                + (fmtETA(p.eta_s) ? ' — ' + fmtETA(p.eta_s) + ' مانده' : '')
                                                + ' — صفحه: ' + p.pages + '، تکه: ' + p.chunks
                                                + (p.failed ? '، خوانده‌نشده: ' + p.failed : '')
                                                + (p.current ? ' — در حال خواندن: ' + short(p.current) : '')
                                                + (p.last_error ? ' — آخرین خطا: ' + p.last_error : '');
                                }
                                function setButtons(run) {
                                        if (startBtn) startBtn.disabled = run;
                                        if (stopBtn) stopBtn.disabled = !run;
                                }
                                function finish(p) {
                                        running = false; retries = 0; setButtons(false);
                                        if (bar) bar.style.width = '100%';
                                        if (!p.pages && p.failed) {
                                                say('خزش تمام شد ولی هیچ صفحه‌ای خوانده نشد (' + p.failed + ' خطا' + (p.last_error ? ': ' + p.last_error : '') + '). معمولاً یعنی هاست اجازهٔ خواندن صفحات خودِ سایت را نمی‌دهد — از میزبانی بخواهید دسترسی خروجی به دامنه‌ی خودتان را باز کند، یا آدرس‌ها را با https کامل در تب «دانش سایت» بدهید.');
                                                return;
                                        }
                                        say('تمام شد — صفحه: ' + p.pages + '، تکه: ' + p.chunks + (p.failed ? '، خوانده‌نشده: ' + p.failed : '') + (p.stats ? '، مجموع تکه‌های خزش: ' + p.stats.chunks : '') + '. دستیار از این پس صفحات را با لینک می‌شناسد.');
                                }
                                function retry(reason) {
                                        if (!running) return;
                                        if (retries >= MAX_RETRY) { fail('ارتباط با سرور برقرار نشد. خزش متوقف مانده ولی پیشرفت شما حفظ شده — دکمه‌ی «ادامهِ خزش» را بزنید.'); return; }
                                        retries++;
                                        var wait = Math.min(30, 2 * retries);
                                        say('توجه: ' + reason + '؛ تلاش مجدد تا ' + wait + ' ثانیه دیگر (تلاش ' + retries + ' از ' + MAX_RETRY + '). پیشرفت حفظ شده است.');
                                        setTimeout(tick, wait * 1000);
                                }
                                function fail(t) { running = false; retries = 0; setButtons(false); say(t); }
                                function tick() {
                                        if (!running) return;
                                        var body = new FormData();
                                        body.append('action', 'haai_crawl_tick');
                                        body.append('nonce', haaiAdmin.nonce);
                                        fetch(haaiAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                                                .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
                                                .then(function (res) {
                                                        if (!running) return;
                                                        var d = null;
                                                        try { d = JSON.parse(res.text); } catch (e) { d = null; }
                                                        var p = d && d.progress ? d.progress : null;
                                                        if (!p) {
                                                                if (parseInt(res.status, 10) === 403 || (res.text || '').indexOf('-1') === 0) {
                                                                        fail('نشست مدیریت منقضی شده است؛ صفحه را تازه‌سازی (F5) کنید و «ادامهِ خزش» را بزنید.');
                                                                        return;
                                                                }
                                                                retry('پاسخ نامعتبر از سرور (کش/فایروال)');
                                                                return;
                                                        }
                                                        retries = 0;
                                                        if (bar) bar.style.width = Math.max(0, Math.min(100, p.percent)) + '%';
                                                        say(describe(p));
                                                        if (p.running) { setTimeout(tick, 500); } else { finish(p); }
                                                })
                                                .catch(function () { retry('اتصال به سرور قطع شد'); });
                                }
                                if (stopBtn) stopBtn.addEventListener('click', function () {
                                        running = false;
                                        var body = new FormData();
                                        body.append('action', 'haai_crawl_stop');
                                        body.append('nonce', haaiAdmin.nonce);
                                        fetch(haaiAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                                                .then(function (r) { return r.json(); })
                                                .then(function () { setButtons(false); say('خزش متوقف شد و پیشرفت حفظ شد؛ با «ادامهِ خزش» از همان‌جا ادامه می‌دهد.'); })
                                                .catch(function () { setButtons(false); say('خزش متوقف شد؛ با «ادامهِ خزش» از همان‌جا ادامه می‌دهد.'); });
                                });
                                // 2.1.16 — BOOT-ORDER FIX (the real «هیچ نواری جلو نمیره»
                                // root cause): this inline script runs MID-PAGE, but the
                                // localized haaiAdmin data prints in the FOOTER
                                // (wp_enqueue_script with $in_footer = true) — at an
                                // immediate boot window.haaiAdmin was ALWAYS undefined
                                // and the ticker silently died on EVERY load, so no
                                // batch was ever fetched («خزش در حال اجراست…» forever
                                // at 0/0, not even a retry message). Boot after
                                // DOMContentLoaded (footer scripts have run by then)
                                // and keep a short retry window so a cached/deferred
                                // footer still ends in an HONEST message, never
                                // silence.
                                var boots = 0;
                                function boot() {
                                        if (!running) return;
                                        if (!window.haaiAdmin) {
                                                if (++boots <= 20) { setTimeout(boot, 500); return; }
                                                fail('اسکریپت پیشرفت بارگذاری نشد (کش یا بهینه‌ساز JS). صفحه را با Ctrl+F5 تازه‌سازی کنید؛ خزش با «ادامهِ خزش» از همین‌جا ادامه می‌یابد.');
                                                return;
                                        }
                                        setButtons(true);
                                        if (wrap) wrap.hidden = false;
                                        tick();
                                }
                                if (document.readyState === 'loading') {
                                        document.addEventListener('DOMContentLoaded', boot);
                                } else {
                                        boot();
                                }
                        })();
                        </script>
                        <?php endif; ?>
                        <?php
        }

        /**
         * Settings-consistency diagnostic (admin only, read-only).
         */
        private static function render_diagnostics() {
                $issues = Haa_Settings::diagnose();
                if ( empty( $issues ) ) {
                        ?>
                        <p class="haai-diag-clean">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                <?php esc_html_e( 'تشخیص تنظیمات: هیچ ناسازگاری یافت نشد.', 'hami-ai-assistant' ); ?>
                        </p>
                        <?php
                        return;
                }
                ?>
                <details class="haai-diagnostics">
                        <summary>
                                <?php esc_html_e( 'تشخیص تنظیمات', 'hami-ai-assistant' ); ?>
                                <span class="haai-badge haai-badge--warn haai-diagnostics__count"><?php echo esc_html( number_format_i18n( count( $issues ) ) ); ?></span>
                        </summary>
                        <div class="haai-table-scroll">
                                <table class="widefat striped haai-table">
                                        <thead>
                                                <tr>
                                                        <th><?php esc_html_e( 'بخش', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'کلید', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'مشکل', 'hami-ai-assistant' ); ?></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                                <?php foreach ( $issues as $issue ) : ?>
                                                        <tr>
                                                                <td><code><?php echo esc_html( $issue['section'] ); ?></code></td>
                                                                <td><code><?php echo esc_html( $issue['key'] ); ?></code></td>
                                                                <td><?php echo esc_html( $issue['issue'] ); ?></td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        </tbody>
                                </table>
                        </div>
                </details>
                <?php
        }

        /**
         * Small health cards. Every check is a cheap local read — the dashboard
         * never makes a network call.
         *
         * `tone` is presentation only: 'ok', 'warn', 'danger' or '' for a plain
         * informational card. It drives the dot colour and nothing else.
         *
         * @return array<int,array{value:string,label:string,hint:string,tone:string}>
         */
        private static function health() {
                $provider = Haa_Settings::provider();
                $has_key  = '' !== (string) $provider['api_key'];
                $woo      = haa_is_woo();

                $out = array();

                $out[] = array(
                        'value' => $has_key ? __( 'متصل', 'hami-ai-assistant' ) : __( 'قطع', 'hami-ai-assistant' ),
                        'label' => __( 'سرویس AI', 'hami-ai-assistant' ),
                        'hint'  => $has_key ? $provider['provider'] : __( 'کلید API ثبت نشده است.', 'hami-ai-assistant' ),
                        'tone'  => $has_key ? 'ok' : 'warn',
                );

                // Hidden is often deliberate (display mode, role rules), so it gets no
                // colour — only the positive state is highlighted.
                $out[] = array(
                        'value' => Haa_Plugin::should_display( Haa_Settings::get() ) ? __( 'نمایش داده می‌شود', 'hami-ai-assistant' ) : __( 'پنهان', 'hami-ai-assistant' ),
                        'label' => __( 'چت‌بات در سایت', 'hami-ai-assistant' ),
                        'hint'  => '',
                        'tone'  => '',
                );

                // Same reasoning: a store without WooCommerce is not a broken store.
                $out[] = array(
                        'value' => $woo ? __( 'فعال', 'hami-ai-assistant' ) : __( 'نصب نیست', 'hami-ai-assistant' ),
                        'label' => __( 'ووکامرس', 'hami-ai-assistant' ),
                        'hint'  => $woo ? '' : __( 'ماژول‌های فروشگاهی غیرفعال می‌مانند.', 'hami-ai-assistant' ),
                        'tone'  => '',
                );

                $sched = wp_next_scheduled( 'haai_daily_cleanup' );
                $out[] = array(
                        'value' => $sched ? __( 'زمان‌بندی شده', 'hami-ai-assistant' ) : __( 'غیرفعال', 'hami-ai-assistant' ),
                        'label' => __( 'پاک‌سازی خودکار', 'hami-ai-assistant' ),
                        'hint'  => $sched ? gmdate( 'Y-m-d H:i', (int) $sched ) : __( 'کرون ثبت نشده است.', 'hami-ai-assistant' ),
                        'tone'  => $sched ? 'ok' : 'danger',
                );

                return $out;
        }
}
