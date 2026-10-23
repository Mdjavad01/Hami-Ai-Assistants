<?php
/**
 * Regression suite: the competitor analysis finally has a head and a tail (2.1.42).
 *
 * Twelfth live report follow-up: the admin ran «تحلیل رقبا» and got
 * «1 سایت، 6 صفحه — آخرین تحلیل…» — then nothing. «خوب این نتیجه‌ش کجا
 * میره؟ سر و ته نداره». Auditing the data flow proved them right:
 *
 *   - the snapshot fed the gaps list and the structure-similarity gate,
 *   - the per-row brief() that feeds competitor headings into prompts had
 *     NO button anywhere (orphaned handler),
 *   - the queue's own outline() built plans with ZERO competitor knowledge,
 *   - a pasted full URL was silently reduced to its bare host on save,
 *     which reads as «url و قبول نمیکنه، فقط دامنه».
 *
 * Ships:
 *   - Haa_Research::related_headings() — the keyword/heading matcher pulled
 *     out of brief() so the queue can use it too.
 *   - Haa_Pipeline::outline() now injects related competitor headings and
 *     the site's content gaps into EVERY queued article's plan prompt.
 *   - Haa_Research::result_digest() + the rebuilt panel: per-site rows
 *     (host, pages, avg words, read headings), shared terms, questions,
 *     and a «نتیجه کجا می‌رود؟» map with the four real consumers.
 *   - The settings hint now says explicitly: full URLs accepted, only the
 *     domain is analysed; the panel names the registered hosts.
 *
 * SELF-CONTAINED: own WP stubs, REAL Haa_Search / Haa_Util / Haa_Settings /
 * Haa_Research / Haa_Pipeline, fakes for the content collaborators, and a
 * live brief()/outline() run that captures the actual model prompt.
 */

define( 'ABSPATH', __DIR__ . '/wp-stub/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HAAI_TEST', true );
define( 'HAAI_OPTION', 'hami_ai_assistant' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['opts']    = array();
$GLOBALS['captured'] = array(); // every generate() call: array(module, operation, system, user)

/* --------------------------------------------------------------------- */
/* Minimal WordPress.                                                      */
/* --------------------------------------------------------------------- */
function __( $s, $d = null ) {
        return $s;
}
function esc_html__( $s, $d = null ) {
        return $s;
}
function esc_html_e( $s, $d = null ) {
        echo $s;
}
function esc_attr_e( $s, $d = null ) {
        echo $s;
}
function esc_html( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
}
function esc_attr( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
}
function esc_url( $s ) {
        return $s;
}
function esc_url_raw( $url, $protocols = array() ) {
        $url = trim( preg_replace( '/[\x00-\x1f\x7f]/', '', (string) $url ) );
        if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
                return '';
        }
        return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function wp_parse_url( $url, $comp = -1 ) {
        return parse_url( $url, $comp );
}
function get_option( $k, $d = false ) {
        return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d;
}
function update_option( $k, $v, $a = null ) {
        $GLOBALS['opts'][ $k ] = $v;
        return true;
}
function delete_option( $k ) {
        unset( $GLOBALS['opts'][ $k ] );
        return true;
}
function current_time( $t ) {
        return 'mysql' === $t ? date( 'Y-m-d H:i:s' ) : time();
}
function sanitize_text_field( $s ) {
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) );
}
function wp_strip_all_tags( $s ) {
        return trim( strip_tags( (string) $s ) );
}
function absint( $n ) {
        return abs( (int) $n );
}
function wp_list_pluck( $arr, $field ) {
        $out = array();
        foreach ( (array) $arr as $row ) {
                $out[] = is_array( $row ) ? ( $row[ $field ] ?? null ) : null;
        }
        return $out;
}
function number_format_i18n( $n ) {
        return (string) $n;
}
function is_wp_error( $v ) {
        return $v instanceof WP_Error;
}
class WP_Error {
        public $code;
        public $message;
        public function __construct( $code = '', $message = '' ) {
                $this->code    = $code;
                $this->message = $message;
        }
        public function get_error_message() {
                return $this->message;
        }
}

/* --------------------------------------------------------------------- */
/* Real plugin classes under test.                                         */
/* --------------------------------------------------------------------- */
require __DIR__ . '/../includes/class-haa-search.php';
require __DIR__ . '/../includes/class-haa-util.php';
require __DIR__ . '/../includes/class-haa-settings.php';
require __DIR__ . '/../includes/class-haa-research.php';
require __DIR__ . '/../includes/class-haa-pipeline.php';

/* --------------------------------------------------------------------- */
/* Fakes for the content collaborators (pipeline + research prompts).      */
/* --------------------------------------------------------------------- */
class Haa_Content {
        public static function no_fabrication() {
                return '- NO-FABRICATION-STUB';
        }
        public static function generate( $system, $user, $opts = array() ) {
                $GLOBALS['captured'][] = array(
                        'module'    => $opts['module'] ?? '',
                        'operation' => $opts['operation'] ?? '',
                        'system'    => $system,
                        'user'      => $user,
                );
                return $GLOBALS['fake_reply'] ?? '';
        }
}
class Haa_Site_Profile {
        public static function block( $topic = '' ) {
                return '';
        }
}
class Haa_Planner {
        const INTENTS   = array( 'informational', 'commercial', 'transactional', 'local' );
        const STATUSES  = array( 'suggested', 'approved', 'queued', 'rejected', 'published' );
        public static function intent_label( $slug ) {
                return array(
                        'informational' => 'اطلاعاتی',
                        'commercial'    => 'تجاری',
                )[ $slug ] ?? $slug;
        }
        public static function site_map() {
                return $GLOBALS['fake_site_map'] ?? array( 'posts' => 0, 'intents' => array(), 'titles' => array() );
        }
        public static function flush_map() {
        }
}

function ok( $label, $cond, $note = '' ) {
        if ( $cond ) {
                $GLOBALS['passed']++;
                echo "  ok  {$label}\n";
        } else {
                $GLOBALS['failed']++;
                echo "FAIL  {$label}" . ( '' !== $note ? "  ({$note})" : '' ) . "\n";
        }
}
$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function set_snapshot( $headings, $sites = null, $terms = array(), $questions = array(), $generated = null ) {
        $sites = $sites ?? array(
                'rival-a.ir' => array(
                        array(
                                'url'   => 'https://rival-a.ir/p1',
                                'title' => 'صفحه یک رقیب',
                                'h2'    => array_slice( (array) $headings, 0, 2 ),
                                'h3'    => array(),
                                'words' => 300,
                        ),
                        array(
                                'url'   => 'https://rival-a.ir/p2',
                                'title' => 'صفحه دو رقیب',
                                'h2'    => array(),
                                'h3'    => array(),
                                'words' => 500,
                        ),
                ),
        );
        Haa_Research::remember( array(
                'generated' => $generated ?? date( 'Y-m-d H:i:s' ),
                'pages'     => array_sum( array_map( 'count', $sites ) ),
                'partial'   => false,
                'mode'      => 'crawl',
                'sites'     => $sites,
                'errors'    => array(),
                'terms'     => $terms,
                'headings'  => (array) $headings,
                'questions' => $questions,
        ) );
}

/* ===================================================================== */
/* 1. The URL complaint: full URLs accepted, host extracted, mailto gone.  */
/* ===================================================================== */
$tag = 'url-accept';

$raw = "example.com\nhttps://rival.ir/blog/post-1\nhttps://WWW.Rival.ir/other\nmailto:x@y.z\n\nnot a url ???";
$GLOBALS['opts'][ HAAI_OPTION ]['research']['competitors'] = $raw;

$sites = Haa_Research::competitors();
ok( "{$tag}: three hosts parsed from mixed input", 3 === count( $sites ), 'got ' . count( $sites ) );
ok( "{$tag}: bare domain gets https origin", 'https://example.com' === $sites[0]['url'] && 'example.com' === $sites[0]['host'] );
ok( "{$tag}: full URL keeps its path (exact page)", 'https://rival.ir/blog/post-1' === $sites[1]['url'] && 'rival.ir' === $sites[1]['host'] && true === $sites[1]['exact'] );
ok( "{$tag}: www host kept distinct", 'www.rival.ir' === $sites[2]['host'] );
ok( "{$tag}: mailto dropped, not repaired", ! in_array( 'x@y.z', wp_list_pluck( $sites, 'host' ), true ) );

/* Save-side contract: the textarea keeps a path when the admin typed one, and
 * stores a bare domain as a domain. This is what makes «exact URL» survive a save. */
$saved = Haa_Settings::sanitize_url_list( "https://rival.ir/blog/post-1\nexample.com" );
ok( "{$tag}: save keeps the path, one per line", "https://rival.ir/blog/post-1\nhttps://example.com" === $saved, var_export( $saved, true ) );

$fields = Haa_Research::fields();
$hint   = '';
foreach ( $fields as $f ) {
        if ( 'research.competitors' === $f['key'] ) {
                $hint = $f['hint'];
        }
}
ok( "{$tag}: hint shows an exact-page example", false !== strpos( $hint, 'example.com/blog/post' ) );
ok( "{$tag}: hint explains sitemap discovery for domains", false !== strpos( $hint, 'سایتمپ' ) );

/* ===================================================================== */
/* 2. related_headings(): the extracted matcher.                           */
/* ===================================================================== */
$tag = 'related';

Haa_Research::remember( array() );
ok( "{$tag}: no snapshot → empty", array() === Haa_Research::related_headings( 'فرش دستباف' ) );

set_snapshot( array(
        'قیمت فرش دستباف',
        'راهنمای خرید فرش ماشینی',
        'تفاوت ظرف استیل و سرامیک',
        'فرش دستباف چیست؟',
) );
$rel = Haa_Research::related_headings( 'فرش دستباف' );
ok( "{$tag}: only keyword-overlapping headings", 3 === count( $rel ), 'got ' . count( $rel ) );
ok( "{$tag}: the matches are the right ones", in_array( 'قیمت فرش دستباف', $rel, true ) && in_array( 'فرش دستباف چیست؟', $rel, true ) && in_array( 'راهنمای خرید فرش ماشینی', $rel, true ) );
ok( "{$tag}: cap respected", 1 === count( Haa_Research::related_headings( 'فرش دستباف', 1 ) ) );
ok( "{$tag}: no overlap → empty", array() === Haa_Research::related_headings( 'زیورآلات طلا' ) );
ok( "{$tag}: empty keyword → empty", array() === Haa_Research::related_headings( '' ) );

/* A row written by an older version must NOT come back: the analysis is
 * never read from wp_options any more. */
$GLOBALS['opts'][ Haa_Research::SNAPSHOT ] = array(
        'generated' => date( 'Y-m-d H:i:s' ),
        'sites'     => array(),
        'headings'  => array( 'قیمت فرش دستباف' ),
);
Haa_Research::remember( array() );
ok( "{$tag}: a legacy option row is ignored", array() === Haa_Research::related_headings( 'فرش دستباف' ) );
unset( $GLOBALS['opts'][ Haa_Research::SNAPSHOT ] );
delete_option( Haa_Research::SNAPSHOT );

/* ===================================================================== */
/* 3. result_digest(): the panel-shaped projection.                        */
/* ===================================================================== */
$tag = 'digest';

Haa_Research::remember( array() );
$empty = Haa_Research::result_digest();
ok( "{$tag}: empty snapshot → empty shape", array() === $empty['rows'] && 0 === $empty['sites'] && '' === $empty['mode'] );

$sites = array(
        'rival-a.ir' => array(
                array( 'url' => 'https://rival-a.ir/p1', 'title' => 'A1', 'h2' => array( 'h2a', 'h2b' ), 'h3' => array(), 'words' => 300 ),
                array( 'url' => 'https://rival-a.ir/p2', 'title' => 'A2', 'h2' => array( 'h2a' ), 'h3' => array( 'h3a' ), 'words' => 500 ),
        ),
        'rival-b.ir' => array(
                array( 'url' => 'https://rival-b.ir/p1', 'title' => 'B1', 'h2' => array( 'q1؟' ), 'h3' => array(), 'words' => 0 ),
        ),
);
set_snapshot( array( 'h one', 'h two' ), $sites, array_fill( 0, 20, 'term' ), array_fill( 0, 12, 'q؟' ) );
$d = Haa_Research::result_digest();
ok( "{$tag}: two site rows", 2 === count( $d['rows'] ) );
ok( "{$tag}: hostA pages=2 avg=400", 2 === $d['rows'][0]['pages'] && 400 === $d['rows'][0]['avg_words'], 'avg=' . $d['rows'][0]['avg_words'] );
ok( "{$tag}: hostA headings deduped", array( 'h2a', 'h2b', 'h3a' ) === array_values( $d['rows'][0]['headings'] ) );
ok( "{$tag}: hostB ai-style row keeps avg 0", 1 === $d['rows'][1]['pages'] && 0 === $d['rows'][1]['avg_words'] );
ok( "{$tag}: terms capped at 15", 15 === count( $d['terms'] ) );
ok( "{$tag}: questions capped at 8", 8 === count( $d['questions'] ) );
ok( "{$tag}: mode + generated ride along", 'crawl' === $d['mode'] && '' !== $d['generated'] );

/* ===================================================================== */
/* 4. gaps() still works on real planner data (feeds the panel + outline). */
/* ===================================================================== */
$tag = 'gaps';

$GLOBALS['fake_site_map'] = array(
        'posts'  => 2,
        'intents' => array(),
        'titles' => array(
                1 => 'فرش دستباف شیراز',
                2 => 'قیمت فرش',
        ),
);
set_snapshot( array(
        'فرش دستباف تبریز قیمت ارزان', // partial overlap, <60% covered → a real gap
        'فرش دستباف شیراز',            // fully covered → not a gap
        'دستباف',                      // single token → skipped
) );
$gaps = Haa_Research::gaps( 10 );
ok( "{$tag}: partial overlap surfaces as a gap", 1 === count( $gaps ) && 'فرش دستباف تبریز قیمت ارزان' === $gaps[0]['heading'], 'count=' . count( $gaps ) );
Haa_Research::remember( array() );
ok( "{$tag}: no snapshot → no gaps", array() === Haa_Research::gaps( 10 ) );

/* ===================================================================== */
/* 5. brief(): same context flow after the loop extraction.                */
/* ===================================================================== */
$tag = 'brief';

set_snapshot( array( 'قیمت فرش دستباف', 'فرش دستباف چیست؟', 'راهنمای خرید فرش ماشینی' ) );
$GLOBALS['fake_reply'] = '{"search_intent":"commercial","keyword":"فرش دستباف","secondary":["فرش تبریز"],"outline":[{"h2":"سرفصل بریف","h3":["زیر بخش"]}],"faq":[],"word_target":1200,"internal_link_goals":[]}';

$r = Haa_Research::brief( array( 'title' => 'خرید فرش دستباف', 'keyword' => 'فرش دستباف', 'intent' => 'commercial' ) );
$brief_call = null;
foreach ( $GLOBALS['captured'] as $c ) {
        if ( 'brief' === $c['operation'] ) {
                $brief_call = $c;
        }
}
ok( "{$tag}: brief ok with outline", ! empty( $r['ok'] ) && 1 === count( (array) $r['brief']['outline'] ) );
ok( "{$tag}: brief prompt carries related competitor headings", null !== $brief_call && false !== strpos( $brief_call['user'], 'سرفصل‌های دیده‌شده در سایت رقبا' ) && false !== strpos( $brief_call['user'], 'قیمت فرش دستباف' ) );
ok( "{$tag}: unrelated headings stay out", null !== $brief_call && false === strpos( $brief_call['user'], 'ظرف استیل' ) );

$GLOBALS['fake_reply'] = '';
$GLOBALS['captured']   = array();

/* ===================================================================== */
/* 6. THE FIX: outline() (queue builds) now consumes the analysis.         */
/* ===================================================================== */
$tag = 'outline';

set_snapshot( array( 'قیمت فرش دستباف', 'فرش دستباف چیست؟', 'راهنمای خرید فرش ماشینی', 'فرش دستباف تبریز قیمت ارزان' ) );
$GLOBALS['fake_site_map'] = array(
        'posts'   => 2,
        'intents' => array(),
        'titles'  => array(
                1 => 'فرش دستباف شیراز',
                2 => 'قیمت فرش',
        ),
);
$GLOBALS['fake_reply'] = '{"sections":[{"h2":"بخش اول","h3":["زیر"],"focus":"تمرکز"}]}';

$secs = Haa_Pipeline::outline( array(
        'title'    => 'خرید فرش دستباف تبریز',
        'keyword'  => 'فرش دستباف',
        'intent'   => 'commercial',
) );
$outline_call = null;
foreach ( $GLOBALS['captured'] as $c ) {
        if ( 'outline' === $c['operation'] ) {
                $outline_call = $c;
        }
}
ok( "{$tag}: outline returns clamped sections", is_array( $secs ) && ! is_wp_error( $secs ) && 1 === count( $secs ) );
ok( "{$tag}: plan prompt carries related competitor headings", null !== $outline_call && false !== strpos( $outline_call['user'], 'سایت‌های رقیب' ) && false !== strpos( $outline_call['user'], 'قیمت فرش دستباف' ) );
ok( "{$tag}: plan prompt carries the site's content gaps", null !== $outline_call && false !== strpos( $outline_call['user'], 'شکاف‌های محتوایی سایت ما' ) && false !== strpos( $outline_call['user'], 'فرش دستباف تبریز قیمت ارزان' ) );
ok( "{$tag}: operation tagged for the usage ledger", null !== $outline_call && 'outline' === $outline_call['operation'] );

/* No analysis in this request → neither block appears; the prompt stays honest. */
$GLOBALS['captured'] = array();
Haa_Research::remember( array() );
Haa_Pipeline::outline( array(
        'title'   => 'خرید فرش دستباف تبریز',
        'keyword' => 'فرش دستباف',
        'intent'  => 'commercial',
) );
$outline_call2 = null;
foreach ( $GLOBALS['captured'] as $c ) {
        if ( 'outline' === $c['operation'] ) {
                $outline_call2 = $c;
        }
}
ok( "{$tag}: no analysis → no competitor block, no crash", null !== $outline_call2 && false === strpos( $outline_call2['user'], 'سایت‌های رقیب' ) && false === strpos( $outline_call2['user'], 'شکاف‌های محتوایی سایت ما' ) );
$GLOBALS['fake_reply']   = '';
$GLOBALS['fake_site_map'] = array( 'posts' => 0, 'intents' => array(), 'titles' => array() );
delete_option( Haa_Research::SNAPSHOT );

/* ===================================================================== */
/* 7. Source contracts: panel shows the result + the «where it goes» map.  */
/* ===================================================================== */
$tag  = 'src';
$awp  = file_get_contents( __DIR__ . '/../modules/class-haa-module-article-writer.php' );
$rsp  = file_get_contents( __DIR__ . '/../includes/class-haa-research.php' );
$pipe = file_get_contents( __DIR__ . '/../includes/class-haa-pipeline.php' );
$main = file_get_contents( __DIR__ . '/../hami-ai-assistant.php' );

ok( "{$tag}: panel renders the result block", false !== strpos( $awp, 'نتیجهٔ تحلیل' ) );
ok( "{$tag}: panel renders per-site pages/avg", false !== strpos( $awp, 'میانگین %d کلمه' ) && false !== strpos( $awp, '%d صفحه' ) );
ok( "{$tag}: panel names the registered hosts as chips", false !== strpos( $awp, 'haai-chip haai-chip--on' ) );
ok( "{$tag}: panel shows read headings per site", false !== strpos( $awp, 'صفحه‌ها و سرفصل‌های خوانده‌شده' ) );
ok( "{$tag}: panel shows shared terms", false !== strpos( $awp, 'واژه‌هایی که در سرفصل‌های رقبا بیشترین تکرار را دارند' ) );
ok( "{$tag}: panel shows competitor questions", false !== strpos( $awp, 'سؤال که رقبا در سرفصل‌هایشان پاسخ داده‌اند' ) );
ok( "{$tag}: the «where the result goes» map exists", false !== strpos( $awp, 'نتیجه کجا می‌رود؟' ) );
ok( "{$tag}: map names the export, the gate, and the honest limit", false !== strpos( $awp, 'تنها نسخه‌ای که می‌ماند' ) && false !== strpos( $awp, 'ساختار شبیه رقیب' ) && false !== strpos( $awp, 'خروجی CSV' ) );
ok( "{$tag}: empty-headings fallback hint added", false !== strpos( $awp, 'از رقبا هیچ سرفصلی خوانده نشد' ) );
ok( "{$tag}: no-sites warn now speaks URL too", false !== strpos( $awp, 'دامنه یا URL کامل' ) );
ok( "{$tag}: research panel still refreshable via AJAX", false !== strpos( $awp, 'data-haai-refresh="haai-research-panel"' ) );
ok( "{$tag}: panel anchor id intact", false !== strpos( $awp, 'id="haai-research-panel"' ) );

ok( "{$tag}: hint text in fields() shows an exact page", false !== strpos( $rsp, 'example.com/blog/post' ) );
ok( "{$tag}: related_headings() exists", false !== strpos( $rsp, 'public static function related_headings(' ) );
ok( "{$tag}: result_digest() exists", false !== strpos( $rsp, 'public static function result_digest(' ) );
ok( "{$tag}: brief() now rides the shared matcher", false !== strpos( $rsp, 'self::related_headings(' ) );
ok( "{$tag}: pipeline outline pulls related headings", false !== strpos( $pipe, 'Haa_Research::related_headings(' ) );
ok( "{$tag}: pipeline outline pulls gaps", false !== strpos( $pipe, 'Haa_Research::gaps( 6 )' ) );

ok( "{$tag}: version header is 2.1.57", false !== strpos( $main, '2.1.57' ) );
ok( "{$tag}: HAAI_VERSION constant is 2.1.57", false !== strpos( $main, "define( 'HAAI_VERSION', '2.1.57' )" ) );

echo "\n--- suite 44: research has a head and a tail (2.1.42) ---\n";
echo "passed: {$GLOBALS['passed']}, failed: {$GLOBALS['failed']}\n";
exit( $GLOBALS['failed'] ? 1 : 0 );
