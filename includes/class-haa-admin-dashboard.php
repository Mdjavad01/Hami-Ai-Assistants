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

                        <?php /* The question every new owner asks first: «چرا دکمه‌ی چت روی سایت کاری نمی‌کند؟»
                               * Three switches decide it, and a cache can fake a fourth. Show them. */ ?>
                        <h2 class="haai-sec-title"><?php esc_html_e( 'چت روی صفحات سایت', 'hami-ai-assistant' ); ?></h2>
                        <div class="haai-panel">
                                <?php
                                $gate  = Haa_Settings::gate();
                                $modes = array(
                                        'shortcode' => __( 'فقط شورتکد', 'hami-ai-assistant' ),
                                        'floating'  => __( 'فقط دکمه‌ی شناور', 'hami-ai-assistant' ),
                                        'both'      => __( 'شورتکد + دکمه‌ی شناور', 'hami-ai-assistant' ),
                                );
                                $rows = array(
                                        array( __( 'افزونه روشن است', 'hami-ai-assistant' ), ! empty( $gate['on'] ), __( 'تنظیمات ← عمومی ← فعال', 'hami-ai-assistant' ) ),
                                        array( __( 'ماژول چت‌بات روشن است', 'hami-ai-assistant' ), ! empty( $gate['chat'] ), __( 'ماژول‌ها ← چت‌بات', 'hami-ai-assistant' ) ),
                                        array(
                                                sprintf( /* translators: %s: display mode. */ __( 'حالت نمایش: %s', 'hami-ai-assistant' ), (string) ( $modes[ $gate['dm'] ] ?? $gate['dm'] ) ),
                                                'shortcode' !== $gate['dm'],
                                                __( 'برای دکمه‌ی شناور، حالت را «شناور» یا «هر دو» بگذارید.', 'hami-ai-assistant' ),
                                        ),
                                );
                                ?>
                                <ul class="haai-diag__list">
                                        <?php foreach ( $rows as $row ) : ?>
                                                <li>
                                                        <span class="haai-badge <?php echo $row[1] ? 'haai-badge--ok' : 'haai-badge--warn'; ?>">
                                                                <?php echo $row[1] ? esc_html__( 'درست', 'hami-ai-assistant' ) : esc_html__( 'باید درست شود', 'hami-ai-assistant' ); ?>
                                                        </span>
                                                        <?php echo esc_html( $row[0] ); ?>
                                                        <?php if ( ! $row[1] ) : ?>
                                                                <span class="haai-field__hint"><?php echo esc_html( $row[2] ); ?></span>
                                                        <?php endif; ?>
                                                </li>
                                        <?php endforeach; ?>
                                </ul>
                                <p class="haai-field__hint">
                                        <?php esc_html_e( 'دکمه دیده می‌شود ولی کلیک کاری نمی‌کند؟ ۱) کش صفحه و کش جاوااسکریپت را پاک کنید. ۲) افزونه‌های «ادغام/تعویق/بهینه‌سازی جاوااسکریپت» را یک‌بار خاموش کنید و صفحه را تست کنید — لودر این افزونه حالا حتی بدون اسکریپت اینلاین هم از داده‌های خود دکمه بالا می‌آید. ۳) اگر صفحه‌ای را در «صفحات ممنوع» گذاشته‌اید، چت همان‌جا نمایش داده نمی‌شود.', 'hami-ai-assistant' );
                                        ?>
                                </p>
                                <p class="haai-plan__actions">
                                        <a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'باز کردن صفحه اصلی سایت', 'hami-ai-assistant' ); ?></a>
                                        <a class="button" href="<?php echo esc_url( Haa_Util::screen_url( 'settings', array( 'tab' => 'general' ) ) ); ?>"><?php esc_html_e( 'تنظیمات نمایش', 'hami-ai-assistant' ); ?></a>
                                </p>
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
                                <?php esc_html_e( 'خزش، محتوای رندرشدهٔ صفحات سایت (منو، فوتر، ویجت‌ها و صفحه تماس) را به پایگاه دانش اضافه می‌کند و شماره تماس، اینستاگرام و لینک صفحه تماس را یک‌جا برای دستیار خلاصه می‌کند. آدرس‌های دلخواه را در تب «دانش سایت» بدهید. ایندکس کاملاً روی دیتابیس خودِ سایت انجام می‌شود و به هیچ سرویس هوش مصنوعی وابسته نیست.', 'hami-ai-assistant' ); ?>
                        </p>
                        <?php
                        // Scope preview — the «اولویت‌بندی / انتخاب صفحه» control sits in
                        // the «دانش سایت» tab, but the admin must see the SCALE of what
                        // is about to be queued right next to the button.
                        $crawl_cfg    = Haa_Crawl::config();
                        $crawl_counts = Haa_Crawl::type_counts();
                        $crawl_targets = Haa_Crawl::targets_full();
                        $crawl_queue   = count( $crawl_targets );
                        $crawl_http    = count( array_filter( $crawl_targets, function ( $t ) { return empty( $t['pid'] ); } ) );
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
                        // DB pages read in milliseconds; only the homepage and
                        // custom URLs pay a real HTTP round-trip (~3 s worst case).
                        $eta_min = (int) max( 1, (int) ceil( ( $crawl_http * 3 + ( $crawl_queue - $crawl_http ) * 0.1 ) / 60 ) );
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
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-actions" data-haai-crawl-form data-haai-noajax>
                                <?php wp_nonce_field( 'haai_admin' ); ?>
                                <input type="hidden" name="action" value="haai_crawl_start">
                                <button type="submit" class="button button-primary" data-haai-crawl-start>
                                        <?php echo empty( $crawl_state['running'] ) ? esc_html__( 'کرال و به‌روزرسانی دانش سایت', 'hami-ai-assistant' ) : esc_html__( 'ادامهِ خزش', 'hami-ai-assistant' ); ?>
                                </button>
                                <?php
                                // 2.1.20 — stop/reset ALWAYS render so a JS start can
                                // reveal them without a reload; when nothing runs they
                                // are display:none (inline style wins over WP admin's
                                // .button{display:inline-block}, the hidden attribute
                                // would not). With JS dead they behave exactly as before:
                                // visible only after a classic POST lands on running=1.
                                ?>
                                <button type="button" class="button" data-haai-crawl-stop<?php echo empty( $crawl_state['running'] ) ? ' style="display:none"' : ''; ?>><?php esc_html_e( 'توقف خزش', 'hami-ai-assistant' ); ?></button>
                                <button type="submit" class="button" name="haai_crawl_reset" value="1" onclick="if (confirm('از نو شروع شود؟ پیشرفت فعلی صفر می‌شود.')) { window.haaiResetClicked = true; return true; } return false;"<?php echo empty( $crawl_state['running'] ) ? ' style="display:none"' : ''; ?>><?php esc_html_e( 'شروع از نو', 'hami-ai-assistant' ); ?></button>
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
                        <?php
                        // 2.1.21 — engine honesty: the crawl no longer depends on an
                        // open browser tab. Say which drivers exist RIGHT NOW, and call
                        // out a stalled run explicitly instead of showing a frozen bar.
                        // diagnose() is fetched HERE so both the engine block and the
                        // 2.1.19 verdict block below share one call.
                        $diag = Haa_Crawl::diagnose();
                        if ( ! empty( $crawl_state['running'] ) ) {
                                $hb_age  = ! empty( $crawl_state['current_at'] ) ? ( time() - (int) $crawl_state['current_at'] ) : 0;
                                $stalled = ( $hb_age > 300 ) && ( empty( $diag['cron_last'] ) || ( time() - (int) $diag['cron_last'] ) > 300 );
                                if ( ! empty( $diag['cron_scheduled'] ) ) {
                                        ?>
                                        <p class="description" style="color:#00707a;">
                                                <?php esc_html_e( 'موتور سرور (کرون دقیقه‌ای) فعال است — خزش حتی با بسته‌بودن مرورگر پیش می‌رود؛ نوار پیشرفت فقط هنگام بازبودن همین صفحه به‌روز می‌شود.', 'hami-ai-assistant' ); ?>
                                        </p>
                                        <?php
                                } elseif ( ! empty( $diag['wp_cron_disabled'] ) ) {
                                        ?>
                                        <p class="description" style="color:#996800;">
                                                <?php esc_html_e( 'WP-Cron روی این هاست غیرفعال است (DISABLE_WP_CRON) — برای پیشرفت خودکار، این صفحه را باز نگه دارید یا از میزبانی بخواهید wp-cron.php را هر دقیقه فراخوانی کند.', 'hami-ai-assistant' ); ?>
                                        </p>
                                        <?php
                                }
                                if ( $stalled ) {
                                        ?>
                                        <p class="description" style="color:#b32d2e;">
                                                <?php
                                                $hb_min = (int) max( 1, round( $hb_age / 60 ) );
                                                echo esc_html( sprintf(
                                                        /* translators: %d: minutes since the crawler last moved. */
                                                        __( 'خزش حدود %d دقیقه است بی‌حرکت مانده و کرون هم دسته‌ای اجرا نکرده است. یک بار «شروع از نو» بزنید؛ اگر تکرار شد، ظرفیت PHP (memory_limit) یا فعالیت افزونه‌های امنیتی را بررسی کنید.', 'hami-ai-assistant' ),
                                                        $hb_min
                                                ) );
                                                ?>
                                        </p>
                                        <?php
                                }
                        }
                        ?>
                        <?php
                        // 2.1.19 — «0 صفحه / 0 تکه» alone taught the admin nothing.
                        // Every empty-KB report turned into guesswork; these verdicts
                        // name the cause in plain Persian next to the badge itself.
                        // ($diag was fetched in the engine block above — shared call.)
                        if ( ! $diag['table_ok'] ) {
                                // A missing chunks table silently swallows every insert —
                                // the crawl "finishes" at 0/0 with no error anywhere. Repair
                                // it right here (dbDelta is idempotent and only runs when
                                // the table is actually absent).
                                Haa_DB::create();
                                $diag['table_ok'] = true;
                                ?>
                                <p class="description" style="color:#b32d2e;">
                                        <?php esc_html_e( 'جدول پایگاه دانش موجود نبود و همین حالا بازسازی شد؛ لطفاً یک بار دیگر «کرال و به‌روزرسانی دانش سایت» را بزنید.', 'hami-ai-assistant' ); ?>
                                </p>
                                <?php
                        }
                        if ( ! $diag['rag_on'] ) {
                                ?>
                                <p class="description" style="color:#b32d2e;">
                                        <?php esc_html_e( '«پایگاه دانش (RAG)» خاموش است — تا روشنش نکنید هیچ صفحه‌ای ایندکس نمی‌شود و دستیار هم از دانش سایت چیزی نمی‌داند. کلید در تب پیشرفته است.', 'hami-ai-assistant' ); ?>
                                </p>
                                <?php
                        }
                        if ( ! empty( $diag['last']['finished_at'] ) && 0 === (int) $diag['last']['pages'] ) {
                                $l = $diag['last'];
                                if ( $l['failed'] > 0 ) {
                                        ?>
                                        <p class="description" style="color:#b32d2e;">
                                                <?php esc_html_e( 'آخرین خزش هیچ صفحه‌ای را ذخیره نکرد — درخواست‌های شبکه به خودِ سایت ناموفق بود.', 'hami-ai-assistant' ); ?>
                                                <?php
                                                if ( '' !== (string) $l['last_error'] ) {
                                                        echo esc_html( sprintf( 'آخرین خطا: %s.', $l['last_error'] ) );
                                                }
                                                esc_html_e( 'برگه‌ها، محصولات و نوشته‌ها از دیتابیس خوانده می‌شوند؛ دکمه‌ی «شروع از نو» را بزنید تا صف از نو ساخته شود.', 'hami-ai-assistant' );
                                                ?>
                                        </p>
                                        <?php
                                } else {
                                        ?>
                                        <p class="description" style="color:#b32d2e;">
                                                <?php esc_html_e( 'آخرین خزش کامل شد اما هیچ صفحه‌ای ذخیره نشد. اگر «پایگاه دانش (RAG)» روشن است، یک بار «شروع از نو» بزنید؛ اگر تکرار شد، متن یکی از برگه‌ها را بررسی کنید.', 'hami-ai-assistant' ); ?>
                                        </p>
                                        <?php
                                }
                        }
                        // Budget hint: a 30-page budget on a 1,700-product shop indexes
                        // almost nothing even when everything works. Say the number.
                        if ( in_array( 'product', (array) $crawl_cfg['types'], true ) && haa_is_woo()
                                && (int) $diag['budget'] < (int) $diag['counts']['product'] + 50 ) {
                                $want = (int) $diag['counts']['product'] + 50;
                                ?>
                                <p class="description" style="color:#996800;">
                                        <?php
                                        echo esc_html( sprintf(
                                                /* translators: 1: current budget, 2: product count, 3: suggested budget. */
                                                __( 'بودجه‌ی خزش فعلی %1$s صفحه است ولی فروشگاه شما %2$s محصول دارد — ربات فقط بخشی را می‌بیند. برای پوشش کامل، بودجه را در تب «دانش سایت» حدود %3$s بگذارید.', 'hami-ai-assistant' ),
                                                number_format_i18n( (int) $diag['budget'] ),
                                                number_format_i18n( (int) $diag['counts']['product'] ),
                                                number_format_i18n( $want )
                                        ) );
                                        ?>
                                </p>
                                <?php
                        }
                        ?>

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
                        // Dashboard crawl control — 2.1.20: START went AJAX too. The
                        // classic POST to admin-post.php redirected back with
                        // ?haai_msg=… : the URL changed, the whole admin reloaded and
                        // every press killed the running ticker («چند بار زدم، نوار
                        // پیشرفت ادامه نیاورد») — while the dashboard never even
                        // printed haai_msg. Now JS intercepts the submit and starts
                        // over admin-ajax: no reload, no URL change, the ticker (and
                        // this script's state) stay alive; the classic POST remains
                        // the no-JS fallback. 2.1.15 hardening kept:
                        //   - never silent: percent, page count, the URL being read
                        //     right now and a live ETA between batches;
                        //   - never frozen: server/network hiccups are retried with
                        //     backoff instead of dying on the first bad response;
                        //   - resumable: stop keeps the cursor, resume continues from
                        //     it, and restart is an explicit reset button.
                        ?>
                        <script>
                        (function () {
                                var bar = document.querySelector('[data-haai-crawl-bar]');
                                var wrap = document.querySelector('[data-haai-crawl-progress]');
                                var status = document.querySelector('[data-haai-crawl-status]');
                                var form = document.querySelector('[data-haai-crawl-form]');
                                var startBtn = document.querySelector('[data-haai-crawl-start]');
                                var stopBtn = document.querySelector('[data-haai-crawl-stop]');
                                var resetBtn = form ? form.querySelector('button[name="haai_crawl_reset"]') : null;
                                var running = <?php echo empty( $crawl_state['running'] ) ? 'false' : 'true'; ?>;
                                var starting = false, retries = 0, boots = 0, epoch = 0;
                                var MAX_RETRY = 6, BOOT_TRIES = 20;
                                var L_IDLE = <?php echo wp_json_encode( __( 'کرال و به‌روزرسانی دانش سایت', 'hami-ai-assistant' ) ); ?>;
                                var L_RESUME = <?php echo wp_json_encode( __( 'ادامهِ خزش', 'hami-ai-assistant' ) ); ?>;

                                function say(t, isErr) {
                                        if (!status) return;
                                        status.textContent = t;
                                        status.style.color = isErr ? '#b32d2e' : '';
                                }
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
                                        // Inline display, not the hidden attribute — WP
                                        // admin's .button{display:inline-block} would beat it.
                                        if (stopBtn) stopBtn.style.display = run ? '' : 'none';
                                        if (resetBtn) resetBtn.style.display = run ? '' : 'none';
                                }
                                function finish(p) {
                                        running = false; retries = 0; starting = false; epoch++;
                                        setButtons(false);
                                        if (startBtn) startBtn.textContent = L_IDLE;
                                        if (bar) bar.style.width = '100%';
                                        if (!p.pages && p.failed) {
                                                say('خزش تمام شد ولی هیچ صفحه‌ای خوانده نشد (' + p.failed + ' خطا' + (p.last_error ? ': ' + p.last_error : '') + '). معمولاً یعنی هاست اجازهٔ خواندن صفحات خودِ سایت را نمی‌دهد — از میزبانی بخواهید دسترسی خروجی به دامنه‌ی خودتان را باز کند، یا آدرس‌ها را با https کامل در تب «دانش سایت» بدهید.');
                                                return;
                                        }
                                        say('تمام شد — صفحه: ' + p.pages + '، تکه: ' + p.chunks + (p.failed ? '، خوانده‌نشده: ' + p.failed + ' (معمولاً صفحه‌ی اول یا آدرس دلخواه — هاست اجازهٔ خواندن خودِ سایت را نمی‌دهد؛ بقیهٔ صفحات از دیتابیس خوانده شده‌اند)' : '') + (p.stats ? '، مجموع تکه‌های خزش: ' + p.stats.chunks : '') + '. دستیار از این پس صفحات را با لینک می‌شناسد.');
                                }
                                function retry(reason) {
                                        if (!running) return;
                                        if (retries >= MAX_RETRY) { fail('ارتباط با سرور برقرار نشد. خزش متوقف مانده ولی پیشرفت شما حفظ شده — دکمه‌ی «ادامهِ خزش» را بزنید.'); return; }
                                        retries++;
                                        var wait = Math.min(30, 2 * retries);
                                        say('توجه: ' + reason + '؛ تلاش مجدد تا ' + wait + ' ثانیه دیگر (تلاش ' + retries + ' از ' + MAX_RETRY + '). پیشرفت حفظ شده است.');
                                        setTimeout(tick, wait * 1000);
                                }
                                function fail(t) { running = false; retries = 0; starting = false; setButtons(false); say(t, true); }
                                function tick() {
                                        if (!running) return;
                                        var e0 = epoch;
                                        var body = new FormData();
                                        body.append('action', 'haai_crawl_tick');
                                        body.append('nonce', haaiAdmin.nonce);
                                        fetch(haaiAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                                                .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
                                                .then(function (res) {
                                                        if (!running || e0 !== epoch) return;
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
                                        running = false; starting = false; epoch++;
                                        var body = new FormData();
                                        body.append('action', 'haai_crawl_stop');
                                        body.append('nonce', haaiAdmin.nonce);
                                        fetch(haaiAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                                                .then(function (r) { return r.json(); })
                                                .then(function () { setButtons(false); if (startBtn) startBtn.textContent = L_RESUME; say('خزش متوقف شد و پیشرفت حفظ شد؛ با «ادامهِ خزش» از همان‌جا ادامه می‌دهد.'); })
                                                .catch(function () { setButtons(false); if (startBtn) startBtn.textContent = L_RESUME; say('خزش متوقف شد؛ با «ادامهِ خزش» از همان‌جا ادامه می‌دهد.'); });
                                });
                                // 2.1.20 — AJAX start: the intercept lives on the FORM, so
                                // both «کرال/ادامه» and «شروع از نو» (submitter detection +
                                // the onclick flag for old Safari) land here instead of a
                                // reload. A plain press while ticking is IGNORED — that
                                // reload was killing the ticker on every press.
                                if (form) form.addEventListener('submit', function (e) {
                                        var isReset = !!(e.submitter && e.submitter.name === 'haai_crawl_reset') || window.haaiResetClicked === true;
                                        window.haaiResetClicked = false;
                                        if (running && !isReset) { e.preventDefault(); return; }
                                        if (!window.haaiAdmin) return; // no-JS env: classic POST fallback (URL changes, still works)
                                        e.preventDefault();
                                        startAjax(isReset);
                                });
                                function startAjax(isReset) {
                                        if (starting) return;
                                        if (running && !isReset) return;
                                        starting = true;
                                        say('در حال آغاز خزش…');
                                        var e0 = epoch;
                                        var body = new FormData();
                                        body.append('action', 'haai_crawl_start');
                                        body.append('nonce', haaiAdmin.nonce);
                                        if (isReset) body.append('reset', '1');
                                        fetch(haaiAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                                                .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
                                                .then(function (res) {
                                                        starting = false;
                                                        if (e0 !== epoch) return; // a stop landed meanwhile
                                                        var d = null;
                                                        try { d = JSON.parse(res.text); } catch (err) { d = null; }
                                                        if (!d) {
                                                                if (parseInt(res.status, 10) === 403 || (res.text || '').indexOf('-1') === 0) {
                                                                        fail('نشست مدیریت منقضی شده است؛ صفحه را تازه‌سازی (F5) کنید و دوباره تلاش کنید.');
                                                                        return;
                                                                }
                                                                say('پاسخ نامعتبر از سرور (کش/فایروال) — به مسیر کلاسیک برمی‌گردیم…', true);
                                                                if (form) form.submit(); // does NOT fire submit → no loop
                                                                return;
                                                        }
                                                        if (!d.ok) { fail(d.message || 'شروع خزش ناموفق بود.'); return; }
                                                        epoch++;
                                                        running = true; retries = 0;
                                                        if (startBtn) startBtn.textContent = L_RESUME;
                                                        setButtons(true);
                                                        if (wrap) wrap.hidden = false;
                                                        if (bar && d.progress) bar.style.width = Math.max(0, Math.min(100, d.progress.percent)) + '%';
                                                        say(d.message || 'خزش شروع شد.');
                                                        if (d.progress && !d.progress.running) { finish(d.progress); } else { setTimeout(tick, 500); }
                                                })
                                                .catch(function () {
                                                        starting = false;
                                                        say('ارسال درخواست ناموفق بود — به مسیر کلاسیک برمی‌گردیم…', true);
                                                        if (form) form.submit();
                                                });
                                }
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
                                function boot() {
                                        if (!running) { setButtons(false); return; }
                                        if (!window.haaiAdmin) {
                                                if (++boots <= BOOT_TRIES) { setTimeout(boot, 500); return; }
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
