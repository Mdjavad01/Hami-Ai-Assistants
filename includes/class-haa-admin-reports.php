<?php
/**
 * «گزارش‌ها» screen (2.1.23): one unified view of the backlog's report asks —
 * index health, AI-bot visits, broken links, product SEO scores, llms.txt and
 * the content-production state. Everything is read from cached options; the
 * heavy work (link HEAD checks, score batches) runs through the guarded
 * haai_reports_action endpoint, one small bounded step per click.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Admin_Reports {

        /**
         * Render the page.
         *
         * @return void
         */
        public static function render() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                $checks      = Haa_Aeo::health_checks();
                $bots        = Haa_Aeo::bot_rows();
                $finds       = Haa_Aeo::links_findings();
                $links_cycle = Haa_Aeo::links_cycle_at();
                $scores      = Haa_Aeo::scores_summary();
                $queue       = Haa_Queue::stats();
                $kb          = Haa_Search::stats();

                $cstate   = Haa_Crawl::get_state();
                $crawled  = (int) ( $cstate['finished_at'] ?? 0 );
                $llms_on  = (bool) Haa_Settings::get( 'knowledge.llms_enabled', 1 );
                $bots_on  = (bool) Haa_Settings::get( 'knowledge.bots_enabled', 1 );
                $links_on = (bool) Haa_Settings::get( 'knowledge.links_enabled', 1 );

                $total_products = 0;
                if ( function_exists( 'haa_is_woo' ) && haa_is_woo() ) {
                        $total_products = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
                }
                $generated = 0;
                $q = new WP_Query( array(
                        'post_type'      => 'post',
                        'post_status'    => 'publish',
                        'meta_key'       => '_haai_generated',
                        'meta_value'     => '1',
                        'posts_per_page' => 1,
                        'fields'         => 'ids',
                ) );
                if ( isset( $q->found_posts ) ) {
                        $generated = (int) $q->found_posts;
                }

                $nonce = wp_create_nonce( 'haai_admin' );
                ?>
                <div class="wrap haai-wrap">
                        <?php Haa_Admin::page_header( __( 'گزارش‌ها', 'hami-ai-assistant' ) ); ?>

                        <p class="haai-hint">
                                <?php esc_html_e( 'هر دکمه یک گام کوچک و محدود برمی‌دارد؛ هیچ‌کدام کل سایت را یک‌جا اسکن نمی‌کنند تا هاست سنگین نشود.', 'hami-ai-assistant' ); ?>
                        </p>

                        <?php self::section_health( $checks ); ?>
                        <?php self::section_bots( $bots, $bots_on ); ?>
                        <?php self::section_links( $finds, $links_cycle, $links_on ); ?>
                        <?php self::section_scores( $scores, $total_products ); ?>
                        <?php self::section_llms( $llms_on ); ?>
                        <?php self::section_content( $queue, $kb, $generated, $crawled ); ?>

                        <script>
                        (function () {
                                var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
                                var AJAX  = ( window.ajaxurl || '/wp-admin/admin-ajax.php' );

                                function call( act ) {
                                        var body = new URLSearchParams();
                                        body.append( 'action', 'haai_reports_action' );
                                        body.append( 'do', act );
                                        body.append( 'nonce', NONCE );
                                        return fetch( AJAX, {
                                                method: 'POST',
                                                credentials: 'same-origin',
                                                body: body
                                        } ).then( function ( r ) { return r.json(); } );
                                }

                                function out( btn, html, isError ) {
                                        var box = btn.closest( 'section' ).querySelector( '[data-haai-out]' );
                                        if ( ! box ) { return; }
                                        box.innerHTML = html;
                                        box.style.color = isError ? '#b32d2e' : '';
                                }

                                function busy( btn, on, label ) {
                                        btn.disabled = on;
                                        if ( label ) { btn.textContent = label; }
                                }

                                function fmt( n ) { return String( n ).replace( /\d/g, function ( d ) { return '۰۱۲۳۴۵۶۷۸۹'[ d ]; } ); }

                                document.querySelectorAll( '[data-haai-act]' ).forEach( function ( btn ) {
                                        var original = btn.textContent;
                                        btn.addEventListener( 'click', function () {
                                                var act = btn.getAttribute( 'data-haai-act' );
                                                if ( 'clear_links' === act && ! window.confirm( 'فهرست پیوندهای خراب پاک شود؟' ) ) { return; }
                                                if ( 'reset_scores' === act && ! window.confirm( 'همه‌ی امتیازهای ذخیره‌شده پاک شوند؟' ) ) { return; }
                                                if ( 'optimize_worst' === act && ! window.confirm( 'محصولاتِ ضعیف برای تولید محتوا در صف قرار بگیرند؟ هر محصول یک درخواست از سرویس AI خرج می‌کند.' ) ) { return; }

                                                busy( btn, true, 'در حال اجرا…' );
                                                out( btn, '…', false );

                                                if ( 'score_batch' !== act ) {
                                                        call( act ).then( function ( res ) {
                                                                busy( btn, false, original );
                                                                if ( ! res || ! res.ok ) {
                                                                        out( btn, ( res && res.error ) ? res.error : 'انجام نشد؛ دوباره تلاش کنید.', true );
                                                                        return;
                                                                }
                                                                if ( 'scan_links' === act && res.res ) {
                                                                        out( btn, 'این دسته: ' + fmt( res.res.checked ) + ' لینک بررسی شد، ' + fmt( res.res.new ) + ' مورد جدید؛ مجموع خراب‌ها: ' + fmt( res.res.broken ) + ' — جدول زیر به‌روز شد. برای ادامه دوباره بزنید.' );
                                                                        window.setTimeout( function () { window.location.reload(); }, 1200 );
                                                                } else if ( 'clear_links' === act ) {
                                                                        out( btn, 'فهرست پاک شد.' );
                                                                        window.setTimeout( function () { window.location.reload(); }, 800 );
                                                                } else if ( 'optimize_worst' === act ) {
                                                                        out( btn, fmt( res.queued ) + ' محصول در صف «محتوای محصول» قرار گرفت' + ( res.remaining ? '؛ ' + fmt( res.remaining ) + ' مورد دیگر برای دسته‌ی بعدی مانده.' : '.' ) );
                                                                } else if ( 'rebuild_llms' === act ) {
                                                                        out( btn, 'بازسازی شد — ' + fmt( res.chars ) + ' نویسه. آدرس: ' + res.url );
                                                                } else if ( 'reset_scores' === act ) {
                                                                        out( btn, 'امتیازها پاک شد.' );
                                                                        window.setTimeout( function () { window.location.reload(); }, 800 );
                                                                }
                                                        }, function () {
                                                                busy( btn, false, original );
                                                                out( btn, 'ارتباط برقرار نشد؛ دوباره تلاش کنید.', true );
                                                        } );
                                                        return;
                                                }

                                                // Score batches: one press keeps pulling small batches (40
                                                // products each) until the cycle completes or 25 pulls pass.
                                                var pulls = 0;
                                                var last  = null;
                                                function loop() {
                                                        pulls++;
                                                        call( 'score_batch' ).then( function ( res ) {
                                                                if ( ! res || ! res.ok ) {
                                                                        busy( btn, false, original );
                                                                        out( btn, ( res && res.error ) ? res.error : 'امتیازدهی انجام نشد.', true );
                                                                        return;
                                                                }
                                                                last = res.res;
                                                                out( btn, 'امتیازدهی… ' + fmt( last.scored ) + ' محصول امتیاز گرفت.' );
                                                                if ( last.complete || pulls >= 25 ) {
                                                                        busy( btn, false, original );
                                                                        out( btn, 'تمام شد — ' + fmt( last.scored ) + ' محصول امتیاز گرفت. جدول پایین به‌روز شد.' );
                                                                        window.setTimeout( function () { window.location.reload(); }, 1200 );
                                                                        return;
                                                                }
                                                                loop();
                                                        }, function () {
                                                                busy( btn, false, original );
                                                                out( btn, 'ارتباط برقرار نشد؛ دوباره تلاش کنید.', true );
                                                        } );
                                                }
                                                loop();
                                        } );
                                } );
                                })();
                        </script>
                </div>
                <?php
        }

        /**
         * Colored status dot (no icon font, no emoji).
         *
         * @param bool $ok   Pass/fail.
         * @param bool $info Informational row (never red).
         * @return string
         */
        private static function dot( $ok, $info = false ) {
                if ( $info ) {
                        $color = '#8c8f94';
                        $text  = __( 'اطلاع', 'hami-ai-assistant' );
                } elseif ( $ok ) {
                        $color = '#00a32a';
                        $text  = __( 'سالم', 'hami-ai-assistant' );
                } else {
                        $color = '#dba617';
                        $text  = __( 'نیاز به توجه', 'hami-ai-assistant' );
                }
                return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr( $color ) . ';margin-inline-end:6px;vertical-align:middle;"></span><span style="vertical-align:middle;">' . esc_html( $text ) . '</span>';
        }

        /**
         * Index health table.
         *
         * @param array $checks health_checks() rows.
         * @return void
         */
        private static function section_health( $checks ) {
                ?>
                <section class="haai-group" style="margin-top:16px;">
                        <h2 class="haai-group__title"><?php esc_html_e( 'سلامت ایندکس', 'hami-ai-assistant' ); ?></h2>
                        <table class="widefat striped">
                                <tbody>
                                <?php foreach ( (array) $checks as $c ) : ?>
                                        <tr>
                                                <td style="width:32%;"><?php echo self::dot( ! empty( $c['ok'] ), ! empty( $c['info'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <strong><?php echo esc_html( (string) $c['label'] ); ?></strong></td>
                                                <td><?php echo esc_html( (string) $c['note'] ); ?></td>
                                        </tr>
                                <?php endforeach; ?>
                                </tbody>
                        </table>
                </section>
                <?php
        }

        /**
         * AI-bot visits, last 15 days.
         *
         * @param array $rows    bot_rows().
         * @param bool  $enabled Toggle state.
         * @return void
         */
        private static function section_bots( $rows, $enabled ) {
                ?>
                <section class="haai-group" style="margin-top:16px;">
                        <h2 class="haai-group__title"><?php esc_html_e( 'بازدید ربات‌های هوش مصنوعی', 'hami-ai-assistant' ); ?></h2>
                        <?php if ( ! $enabled ) : ?>
                                <p class="description"><?php esc_html_e( 'شمارش ربات‌ها خاموش است (تنظیمات ← دانش سایت).', 'hami-ai-assistant' ); ?></p>
                        <?php elseif ( empty( $rows ) ) : ?>
                                <p class="description"><?php esc_html_e( 'هنوز بازدیدی از ربات‌های هوش مصنوعی (GPTBot، PerplexityBot، ClaudeBot و…) ثبت نشده است. تا ربات‌ها بیایند چند روز صبر کنید؛ فایل llms.txt راهشان را هموارتر می‌کند.', 'hami-ai-assistant' ); ?></p>
                        <?php else : ?>
                                <table class="widefat striped">
                                        <thead><tr>
                                                <th><?php esc_html_e( 'روز', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'ربات‌ها', 'hami-ai-assistant' ); ?></th>
                                        </tr></thead>
                                        <tbody>
                                        <?php foreach ( (array) $rows as $row ) : ?>
                                                <tr>
                                                        <td style="width:18%;"><?php echo esc_html( (string) $row['day'] ); ?></td>
                                                        <td>
                                                                <?php
                                                                $parts = array();
                                                                $total = 0;
                                                                foreach ( (array) $row['counts'] as $name => $n ) {
                                                                        $total  += (int) $n;
                                                                        $parts[] = esc_html( (string) $name ) . ': ' . esc_html( number_format_i18n( (int) $n ) );
                                                                }
                                                                echo esc_html( number_format_i18n( $total ) ) . ' بازدید — ' . implode( '، ', $parts );
                                                                ?>
                                                        </td>
                                                </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                </table>
                                <p class="description"><?php esc_html_e( 'این شمارش فقط «خواندن» ربات‌هاست. اینکه آیا جواب چت‌بات‌ها به ارجاع واقعی تبدیل شده، به گزارش referrer سرور یا ابزار تحلیلی شما برمی‌گردد.', 'hami-ai-assistant' ); ?></p>
                        <?php endif; ?>
                </section>
                <?php
        }

        /**
         * Broken links: findings table + the two manual buttons.
         *
         * @param array $finds       links_findings().
         * @param int   $links_cycle Last full-cycle timestamp.
         * @param bool  $enabled     Toggle state.
         * @return void
         */
        private static function section_links( $finds, $links_cycle, $enabled ) {
                ?>
                <section class="haai-group" style="margin-top:16px;">
                        <h2 class="haai-group__title"><?php esc_html_e( 'پیوندهای خراب', 'hami-ai-assistant' ); ?></h2>
                        <p>
                                <button type="button" class="button" data-haai-act="scan_links"><?php esc_html_e( 'بررسی اکنون (یک دسته کوچک)', 'hami-ai-assistant' ); ?></button>
                                <button type="button" class="button" data-haai-act="clear_links"><?php esc_html_e( 'پاک‌کردن فهرست', 'hami-ai-assistant' ); ?></button>
                        </p>
                        <p class="haai-hint" data-haai-out></p>
                        <?php if ( ! $enabled ) : ?>
                                <p class="description"><?php esc_html_e( 'چکر شبانه خاموش است (تنظیمات ← دانش سایت). با دکمه‌ی بالا می‌توانید هر بار یک دسته را دستی بررسی کنید.', 'hami-ai-assistant' ); ?></p>
                        <?php else : ?>
                                <p class="description">
                                        <?php
                                        if ( $links_cycle > 0 ) {
                                                echo esc_html( sprintf( /* translators: %s: date. */ __( 'آخرین دور کامل بررسی: %s. هر شب دسته‌ی بعدی ادامه می‌یابد.', 'hami-ai-assistant' ), date_i18n( 'Y/m/d H:i', $links_cycle ) ) );
                                        } else {
                                                esc_html_e( 'دور کامل اول هنوز تمام نشده؛ هر شب لینک‌های بعدی بررسی می‌شوند.', 'hami-ai-assistant' );
                                        }
                                        ?>
                                </p>
                        <?php endif; ?>
                        <?php if ( empty( $finds ) ) : ?>
                                <p class="description"><?php esc_html_e( 'تا این لحظه پیوند خرابی ثبت نشده است.', 'hami-ai-assistant' ); ?></p>
                        <?php else : ?>
                                <table class="widefat striped">
                                        <thead><tr>
                                                <th><?php esc_html_e( 'نوشته', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'پیوند', 'hami-ai-assistant' ); ?></th>
                                                <th style="width:22%;"><?php esc_html_e( 'مشکل', 'hami-ai-assistant' ); ?></th>
                                        </tr></thead>
                                        <tbody>
                                        <?php foreach ( array_slice( $finds, 0, 30, true ) as $f ) : ?>
                                                <tr>
                                                        <td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $f['pid'] ) ); ?>"><?php echo esc_html( (string) $f['title'] ); ?></a></td>
                                                        <td><a href="<?php echo esc_url( (string) $f['url'] ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( wp_html_excerpt( (string) $f['url'], 60, '…' ) ); ?></a></td>
                                                        <td><?php
                                                                $code = (int) $f['code'];
                                                                if ( $code > 0 ) {
                                                                        echo esc_html( sprintf( /* translators: %d: HTTP code. */ __( 'HTTP %d', 'hami-ai-assistant' ), $code ) );
                                                                } else {
                                                                        echo esc_html( (string) $f['err'] );
                                                                }
                                                        ?></td>
                                                </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                </table>
                                <?php if ( count( $finds ) > 30 ) : ?>
                                        <p class="description"><?php echo esc_html( sprintf( /* translators: %d: count. */ __( 'و %d مورد دیگر…', 'hami-ai-assistant' ), count( $finds ) - 30 ) ); ?></p>
                                <?php endif; ?>
                        <?php endif; ?>
                </section>
                <?php
        }

        /**
         * Product SEO scores: distribution, worst list, three actions.
         *
         * @param array $scores         scores_summary().
         * @param int   $total_products Published product count.
         * @return void
         */
        private static function section_scores( $scores, $total_products ) {
                $unscored = max( 0, $total_products - (int) $scores['scored'] );
                ?>
                <section class="haai-group" style="margin-top:16px;">
                        <h2 class="haai-group__title"><?php esc_html_e( 'امتیاز سئوی محصولات', 'hami-ai-assistant' ); ?></h2>
                        <p>
                                <button type="button" class="button button-primary" data-haai-act="score_batch"><?php esc_html_e( 'محاسبه‌ی امتیازها', 'hami-ai-assistant' ); ?></button>
                                <button type="button" class="button" data-haai-act="optimize_worst"><?php esc_html_e( 'بهینه‌سازی گروهی ضعیف‌ها', 'hami-ai-assistant' ); ?></button>
                                <button type="button" class="button" data-haai-act="reset_scores"><?php esc_html_e( 'شروع دوباره', 'hami-ai-assistant' ); ?></button>
                        </p>
                        <p class="haai-hint" data-haai-out></p>
                        <p class="description">
                                <?php
                                echo esc_html( sprintf(
                                        /* translators: 1: excellent, 2: mid, 3: weak, 4: unscored. */
                                        __( 'عالی: %1$s · متوسط: %2$s · ضعیف: %3$s · بدون امتیاز: %4$s', 'hami-ai-assistant' ),
                                        number_format_i18n( (int) $scores['good'] ),
                                        number_format_i18n( (int) $scores['mid'] ),
                                        number_format_i18n( (int) $scores['low'] ),
                                        number_format_i18n( $unscored )
                                ) );
                                ?>
                        </p>
                        <?php if ( empty( $scores['worst'] ) ) : ?>
                                <p class="description"><?php esc_html_e( 'هنوز امتیازی محاسبه نشده؛ «محاسبه‌ی امتیازها» چند دسته کوچک برمی‌دارد و ضعیف‌ها را پایین فهرست می‌کند.', 'hami-ai-assistant' ); ?></p>
                        <?php else : ?>
                                <table class="widefat striped">
                                        <thead><tr>
                                                <th><?php esc_html_e( 'محصول', 'hami-ai-assistant' ); ?></th>
                                                <th style="width:12%;"><?php esc_html_e( 'امتیاز', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'مهم‌ترین مشکلات', 'hami-ai-assistant' ); ?></th>
                                        </tr></thead>
                                        <tbody>
                                        <?php foreach ( (array) $scores['worst'] as $row ) : ?>
                                                <tr>
                                                        <td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['pid'] ) ); ?>"><?php echo esc_html( (string) $row['title'] ); ?></a></td>
                                                        <td><strong><?php echo esc_html( number_format_i18n( (int) $row['score'] ) ); ?></strong></td>
                                                        <td><?php echo esc_html( implode( '؛ ', array_slice( (array) $row['issues'], 0, 3 ) ) ); ?></td>
                                                </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                </table>
                                <p class="description"><?php esc_html_e( '«بهینه‌سازی گروهی» محصولاتِ زیر ۷۰ را در صف ماژول «محتوای محصول» می‌گذارد؛ تولید هر محصول یک درخواست از سرویس AI خرج می‌کند و پیش‌نمایش‌ها در همان ماژول برای بازبینی شما می‌مانند.', 'hami-ai-assistant' ); ?></p>
                        <?php endif; ?>
                </section>
                <?php
        }

        /**
         * llms.txt status + rebuild.
         *
         * @param bool $enabled Toggle state.
         * @return void
         */
        private static function section_llms( $enabled ) {
                ?>
                <section class="haai-group" style="margin-top:16px;">
                        <h2 class="haai-group__title"><?php esc_html_e( 'فایل llms.txt', 'hami-ai-assistant' ); ?></h2>
                        <?php if ( ! $enabled ) : ?>
                                <p class="description"><?php esc_html_e( 'خاموش است (تنظیمات ← دانش سایت).', 'hami-ai-assistant' ); ?></p>
                        <?php else : ?>
                                <p>
                                        <a class="button" href="<?php echo esc_url( Haa_Aeo::llms_url() ); ?>" target="_blank" rel="noreferrer noopener"><?php esc_html_e( 'دیدن فایل', 'hami-ai-assistant' ); ?></a>
                                        <button type="button" class="button" data-haai-act="rebuild_llms"><?php esc_html_e( 'بازسازی', 'hami-ai-assistant' ); ?></button>
                                </p>
                                <p class="haai-hint" data-haai-out></p>
                                <p class="description"><?php esc_html_e( 'این فایل معرفی متنی سایت (صفحات کلیدی، راه‌های ارتباطی، دسته‌ها و محتوای تازه) است؛ ربات‌های ChatGPT و Perplexity آن را می‌خوانند. پایان هر خزش، خودکار به‌روز می‌شود.', 'hami-ai-assistant' ); ?></p>
                        <?php endif; ?>
                </section>
                <?php
        }

        /**
         * Content production state.
         *
         * @param array $queue    Haa_Queue::stats().
         * @param array $kb       Haa_Search::stats().
         * @param int   $generated Published generated posts.
         * @param int   $crawled   Last crawl finish timestamp.
         * @return void
         */
        private static function section_content( $queue, $kb, $generated, $crawled ) {
                ?>
                <section class="haai-group" style="margin-top:16px;">
                        <h2 class="haai-group__title"><?php esc_html_e( 'تولید محتوا', 'hami-ai-assistant' ); ?></h2>
                        <table class="widefat striped">
                                <tbody>
                                        <tr><td style="width:32%;"><strong><?php esc_html_e( 'صف کار', 'hami-ai-assistant' ); ?></strong></td>
                                                <td><?php echo esc_html( sprintf(
                                                        /* translators: 1: pending, 2: running, 3: done, 4: failed. */
                                                        __( 'در صف: %1$s · در حال اجرا: %2$s · انجام‌شده: %3$s · ناموفق: %4$s', 'hami-ai-assistant' ),
                                                        number_format_i18n( (int) $queue['pending'] ),
                                                        number_format_i18n( (int) $queue['running'] ),
                                                        number_format_i18n( (int) $queue['done'] ),
                                                        number_format_i18n( (int) $queue['failed'] )
                                                ) ); ?></td></tr>
                                        <tr><td><strong><?php esc_html_e( 'مقاله‌های منتشرشده‌ی تولیدی', 'hami-ai-assistant' ); ?></strong></td>
                                                <td><?php echo esc_html( number_format_i18n( $generated ) ); ?></td></tr>
                                        <tr><td><strong><?php esc_html_e( 'پایگاه دانش چت‌بات', 'hami-ai-assistant' ); ?></strong></td>
                                                <td><?php echo esc_html( sprintf(
                                                        /* translators: 1: indexed posts, 2: chunks. */
                                                        __( '%1$s صفحه / %2$s تکه', 'hami-ai-assistant' ),
                                                        number_format_i18n( (int) $kb['items'] ),
                                                        number_format_i18n( (int) $kb['chunks'] )
                                                ) ); ?></td></tr>
                                        <tr><td><strong><?php esc_html_e( 'آخرین خزش کامل', 'hami-ai-assistant' ); ?></strong></td>
                                                <td><?php echo esc_html( $crawled > 0 ? date_i18n( 'Y/m/d H:i', $crawled ) : __( 'هنوز خزشی تمام نشده', 'hami-ai-assistant' ) ); ?></td></tr>
                                </tbody>
                        </table>
                </section>
                <?php
        }
}
