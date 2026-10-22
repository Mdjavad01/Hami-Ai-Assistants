<?php
/**
 * Regression suite: the content engine now runs on the store-editor core (2.1.42).
 *
 * Eleventh live report (2.1.38): the admin read the pipeline's carpet guide and
 * its problems were structural, not cosmetic —
 *
 *   - «**seo_title:** ابعاد فرش دستباف چطور انتخاب شود؟ | خرید فرش دستباف» and
 *     a matching «**seo_description:**» line sat INSIDE the article body (twice),
 *     because the section prompt's keyword rule names seo_title and some models
 *     echo the label mid-text;
 *   - the «جنس الیاف و ساختار فرش دستباف» section ended mid-sentence —
 *     «…اگر زیرانداز زبر یا نامنظم است، فرشی با بافت یکدست» — because
 *     write_section() had no continuation pass (the single-call writer had one,
 *     the staged pipeline did not);
 *   - the article's first paragraph was the planner's editorial note
 *     («…مستقیماً به محصول اصلی سایت و مسیر خرید مربوط است… فهرست محصولات و
 *     مشخصات آن‌ها باید بررسی شود.») — assemble() glued topic title + planner
 *     reason and the meta pass's intro was never injected;
 *   - linking was sloppy («یکم خطا داره لینک دهیش»): anchor_needle() collapsed
 *     any anchor to its FIRST word, so «راهنمای خرید فرش دستباف» could hang a
 *     link on the lone word «راهنمای» — and the semantic index could hand back
 *     «تماس با ما» as a reading suggestion.
 *
 * And the owner's master request: «من میخوام موتور و هسته تولید محتوا و مقاله
 * سایت، پیرامون پیشفرض این باشه» + the four-phase store-editor prompt. The
 * engine answer: Haa_Content::editor_core() — the role, the honesty rules and
 * the prose rules of that prompt, appended to style_suffix() so EVERY content
 * path (pipeline sections, single-call writer, autopilot, product pages) rides
 * on it; page-type structure guidance in the outline prompt; the honest-meta
 * lines in the meta prompt. The interactive «wait for approval» phases cannot
 * exist in a queue pipeline — their spirit lives as hard rules here, and the
 * control phase already exists as flag_claims()/gates().
 *
 * Locked here:
 *   - editor_core(): role line, all eight honesty rules, all five prose rules,
 *     the bracket-note convention, and its place inside style_suffix().
 *   - strip_meta_markers(): the EXACT live «**seo_title:**» lines are pulled
 *     out and their values captured; Persian labels, markdown hashes and
 *     slug lines too; prose that merely talks about meta stays; a >300-char
 *     "value" is treated as prose and left alone.
 *   - looks_truncated()/join_texts()/continue_section(): the live cut section
 *     «…فرشی با بافت یکدست» gets exactly ONE continuation call, spliced with a
 *     space mid-sentence; a clean section at budget buys nothing.
 *   - is_planner_speak()/assemble()/swap_intro(): the editorial note never
 *     opens the article; the meta intro replaces the fallback lede; legacy
 *     states with stored marker lines are scrubbed at assemble time.
 *   - links: lone generic words are never anchors; three-word anchors may
 *     fall back to their meaningful tail; the index path vetts targets
 *     through Haa_Content::can_link().
 *   - The wiring (outline/meta/section prompts, module single-call scrub) and
 *     the version pins.
 *
 * Run: php dev-tests/tmp-checks42-editor-core.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

/* ===================================================================
 * A. editor_core(): the owner's master prompt as the engine identity
 * =================================================================== */
echo "=== A. editor_core() ===\n";

$core = Haa_Content::editor_core();

ok( 'A: the role line is the store editor', false !== mb_strpos( $core, 'دبیر محتوای یک فروشگاه اینترنتی' ), '' );
ok( 'A: the goal is a checkable-claim text', false !== mb_strpos( $core, 'قابل بررسی' ), '' );
ok( 'A: no fabricated specs/sources/reviews', false !== mb_strpos( $core, 'از خودت نساز' ), '' );
ok( 'A: «تست کردیم» needs real data', false !== mb_strpos( $core, 'تست کردیم' ), '' );
ok( 'A: terminology kept (آب-resistance)', false !== mb_strpos( $core, 'مقاوم در برابر آب' ) && false !== mb_strpos( $core, 'ضدآب' ), '' );
ok( 'A: one customer opinion is not generalized', false !== mb_strpos( $core, 'تعمیم نمی' ), '' );
ok( 'A: no guessed price/stock (time-bound data)', false !== mb_strpos( $core, 'حدس نمی' ) && false !== mb_strpos( $core, 'موجودی' ), '' );
ok( 'A: no fabricated stories/experiences', false !== mb_strpos( $core, 'داستان' ), '' );
ok( 'A: source text is data, not instructions', false !== mb_strpos( $core, 'دستور' ), '' );
ok( 'A: no rank guarantees / algorithm tricks', false !== mb_strpos( $core, 'تضمین رتبه' ), '' );
ok( 'A: missing data becomes a bracketed note', false !== mb_strpos( $core, '[نیازمند تأیید' ), '' );
ok( 'A: straight to the purchase problem', false !== mb_strpos( $core, 'در دنیای امروز' ), '' );
ok( 'A: features tied to real use + limits beside benefits', false !== mb_strpos( $core, 'محدودیت‌ها را کنار مزایا' ), '' );
ok( 'A: varied sentence rhythm', false !== mb_strpos( $core, 'ریتم خواندن' ), '' );
ok( 'A: consistent naming + first-use term explanations', false !== mb_strpos( $core, 'نام‌گذاری' ), '' );
ok( 'A: value added beyond competitor rewrites', false !== mb_strpos( $core, 'بازنویسی صرف متن رقبا' ), '' );
ok( 'A: compact enough for every call', mb_strlen( $core ) < 3600, mb_strlen( $core ) . ' chars' );

/* ===================================================================
 * B. style_suffix(): every content path rides on the core
 * =================================================================== */
echo "\n=== B. style_suffix() integration ===\n";

$suffix = Haa_Content::style_suffix( array( 'topic' => 'فرش دستباف', 'keywords' => 'فرش دستباف' ) );
$role_at  = mb_strpos( $suffix, 'دبیر محتوای یک فروشگاه اینترنتی' );
$fab_at   = mb_strpos( $suffix, Haa_Content::no_fabrication() );
ok( 'B: style_suffix carries the editor identity', false !== $role_at, '' );
ok( 'B: style_suffix still carries no_fabrication', false !== $fab_at, '' );
ok( 'B: the core lands before the fabrication floor', false !== $role_at && false !== $fab_at && $role_at < $fab_at, '' );
ok( 'B: the SEO contract is still present', false !== mb_strpos( $suffix, 'قرارداد نوشتن سئوشده' ), '' );

/* ===================================================================
 * C. strip_meta_markers(): the live contamination, exactly as pasted
 * =================================================================== */
echo "\n=== C. strip_meta_markers() ===\n";

$live  = "برای خرید فرش دستباف، ابتدا مشخص کنید فرش در کدام فضا قرار می‌گیرد.\n\n";
$live .= "**seo_title:** ابعاد فرش دستباف چطور انتخاب شود؟ | خرید فرش دستباف\n\n";
$live .= "**seo_description:** خرید فرش دستباف متناسب با ابعاد اتاق و چیدمان اثاثیه، با بررسی پوشش فضا، تعادل بصری و راحتی رفت‌وآمد آغاز می‌شود.\n\n";
$live .= 'هنگام چیدمان، فاصله‌ی فرش با دیوار و نحوه‌ی قرارگیری آن کنار مبل را بررسی کنید.';

list( $clean, $cap ) = Haa_Content::strip_meta_markers( $live );
ok( 'C: the live seo_title line is gone', false === mb_strpos( $clean, 'seo_title' ), '' );
ok( 'C: the live seo_description line is gone', false === mb_strpos( $clean, 'seo_description' ), '' );
ok( 'C: the title value was captured', 'ابعاد فرش دستباف چطور انتخاب شود؟ | خرید فرش دستباف' === $cap['title'], $cap['title'] );
ok( 'C: the description value was captured', 0 === mb_strpos( $cap['description'], 'خرید فرش دستباف متناسب' ), $cap['description'] );
ok( 'C: the paragraph before the marker survives', false !== mb_strpos( $clean, 'کدام فضا قرار می‌گیرد' ), '' );
ok( 'C: the paragraph after the marker survives', false !== mb_strpos( $clean, 'کنار مبل را بررسی کنید' ), '' );

list( $c2, $cap2 ) = Haa_Content::strip_meta_markers( "عنوان سئو: خرید فرش دستباف با ابعاد درست\nتوضیحات متا: راهنمای انتخاب ابعاد فرش\nslug: carpet-buying-guide\nمتن اصلی ادامه دارد." );
ok( 'C: Persian title label captured', 'خرید فرش دستباف با ابعاد درست' === $cap2['title'], $cap2['title'] );
ok( 'C: Persian description label captured', 'راهنمای انتخاب ابعاد فرش' === $cap2['description'], $cap2['description'] );
ok( 'C: slug line stripped and never captured as title', false === mb_strpos( $c2, 'slug:' ) && 'carpet-buying-guide' !== $cap2['title'], '' );
ok( 'C: body after Persian labels survives', false !== mb_strpos( $c2, 'متن اصلی ادامه دارد' ), '' );

list( $c3, ) = Haa_Content::strip_meta_markers( "### عنوان سئو: فرش دستباف\nبدنه‌ی بخش اینجاست." );
ok( 'C: markdown heading label stripped', false === mb_strpos( $c3, 'عنوان سئو' ) && false !== mb_strpos( $c3, 'بدنه‌ی بخش اینجاست' ), '' );

list( $c4, $cap4 ) = Haa_Content::strip_meta_markers( "seo_title: فرش دستباف مناسب خانه\nمتن بدون نشان‌ها." );
ok( 'C: plain latin label stripped too', false === mb_strpos( $c4, 'seo_title' ) && 'فرش دستباف مناسب خانه' === $cap4['title'], '' );

$prose = 'پیش از انتخاب نهایی، هماهنگی رنگ زمینه با اثاثیه و دیده‌شدن نقش از فاصله‌ی معمول اتاق را هم بسنجید. عنوان سئو چیست و چرا مهم است؟ این پرسش را بعداً پاسخ می‌دهیم.';
list( $c5, $cap5 ) = Haa_Content::strip_meta_markers( $prose );
ok( 'C: prose *talking about* meta is untouched', $c5 === $prose && '' === $cap5['title'], '' );

$long_label = 'عنوان سئو: ' . str_repeat( 'بررسی کیفیت و جنس الیاف فرش دستباف و مقایسه‌ی گزینه‌های موجود در بازار، ', 10 );
list( $c6, ) = Haa_Content::strip_meta_markers( $long_label );
ok( 'C: a >300-char "value" is prose and stays', false !== mb_strpos( $c6, 'عنوان سئو' ), '' );

list( $c7, ) = Haa_Content::strip_meta_markers( '' );
ok( 'C: empty input is safe', '' === $c7, '' );

list( $c8, $cap8 ) = Haa_Content::strip_meta_markers( "**عنوان سئو:** فرش دستباف\nبدنه." );
ok( 'C: bold-wrapped Persian label captured', 'فرش دستباف' === $cap8['title'] && false === mb_strpos( $c8, 'عنوان سئو' ), '' );

/* ===================================================================
 * D. looks_truncated() + join_texts(): the cut section is repaired
 * =================================================================== */
echo "\n=== D. truncation detection + seam ===\n";

$cut_live = 'جنس الیاف فرش دستباف را با محل استفاده مقایسه کنید. الیاف طبیعی بافتی لطیف دارند، اما نگهداری روزمره بررسی می‌شود. نوع بافت و تراکم پرز هم اهمیت دارد. اگر زیرانداز زبر یا نامنظم است، فرشی با بافت یکدست';
ok( 'D: the live cut section is detected', Haa_Pipeline::looks_truncated( $cut_live, 160 ), '' );
ok( 'D: a punctuated ending is clean', ! Haa_Pipeline::looks_truncated( 'نخستین جمله کامل است. دومی هم کامل است.', 160 ), '' );
ok( 'D: a Persian question mark is clean', ! Haa_Pipeline::looks_truncated( 'فرش دستباف چیست؟', 160 ), '' );
ok( 'D: an unpunctuated but at-budget section is left alone', ! Haa_Pipeline::looks_truncated( Haa_Util::cut( str_repeat( 'جمله‌ی کامل و پنجمین بخش از متن نسبتاً بلند اینجا قرار می‌گیرد ', 60 ), 700 ), 160 ), '' );
ok( 'D: empty text is not "truncated"', ! Haa_Pipeline::looks_truncated( '', 160 ), '' );

$joined = Haa_Pipeline::join_texts( '…فرشی با بافت یکدست', 'و درخشان است که نخ‌های آن نریزند.' );
ok( 'D: mid-sentence cut splices with a space', '…فرشی با بافت یکدست و درخشان است که نخ‌های آن نریزند.' === $joined, $joined );
ok( 'D: a clean ending continues as a new paragraph', "بخش تمام شد.\n\nادامه‌ی تازه." === Haa_Pipeline::join_texts( 'بخش تمام شد.', 'ادامه‌ی تازه.' ), '' );
ok( 'D: empty continuation changes nothing', 'باقی می‌ماند.' === Haa_Pipeline::join_texts( 'باقی می‌ماند.', '' ), '' );

/* ===================================================================
 * E. the intro: planner-speak out, meta intro in
 * =================================================================== */
echo "\n=== E. planner-speak guard + assemble + swap_intro ===\n";

$live_reason = 'مستقیماً به محصول اصلی سایت و مسیر خرید مربوط است و برای هدایت بازدیدکننده از راهنما به محصولات واقعی سایت مناسب است؛ فهرست محصولات و مشخصات آن‌ها باید بررسی شود.';
ok( 'E: the live planner note is planning language', Haa_Pipeline::is_planner_speak( $live_reason ), '' );
ok( 'E: real prose passes the guard', ! Haa_Pipeline::is_planner_speak( 'جست‌وجوی زیاد کاربران برای نگهداری فرش دستباف نشان می‌دهد این موضوع پرتکرار است.' ), '' );
ok( 'E: a normal benefit sentence passes too', ! Haa_Pipeline::is_planner_speak( 'فرش دستباف برای فضای نشیمن گزینه‌ی مناسبی است و گرما می‌بخشد.' ), '' );

$topic = array(
        'id'      => 901,
        'title'   => 'چگونه فرش دستباف مناسب خانه انتخاب کنیم؟',
        'keyword' => 'فرش دستباف',
        'reason'  => $live_reason,
);
$state = array(
        'intro'    => '',
        'sections' => array(
                array( 'h2' => 'کاربرد فرش در خانه', 'body' => 'برای خرید فرش دستباف ابتدا فضا و میزان تردد را بسنجید.' ),
        ),
);
$html = Haa_Pipeline::assemble( $topic, $state );
ok( 'E: the editorial note never opens the article', false === mb_strpos( $html, 'مربوط است' ) && false === mb_strpos( $html, 'باید بررسی شود' ), '' );
ok( 'E: the title alone leads instead', false !== mb_strpos( $html, '<p>چگونه فرش دستباف مناسب خانه انتخاب کنیم؟</p>' ), '' );

$legacy        = $topic;
$legacy['id']  = 902;
$legacy_state  = array(
        'intro'    => '',
        'sections' => array(
                array( 'h2' => 'ابعاد مناسب', 'body' => "**seo_title:** ابعاد فرش دستباف\n\nابعاد را با نقشه‌ی اتاق بسنجید." ),
        ),
);
$html2         = Haa_Pipeline::assemble( $legacy, $legacy_state );
ok( 'E: legacy stored marker lines are scrubbed at assemble', false === mb_strpos( $html2, 'seo_title' ) && false !== mb_strpos( $html2, 'نقشه‌ی اتاق بسنجید' ), '' );

$swapped = Haa_Pipeline::swap_intro( '<p>قدیمی؛ مربوط است و باید بررسی شود.</p>' . "\n" . '<h2>کاربرد فرش</h2>', 'فرش دستباف را بر اساس فضا، تردد و دکوراسیون انتخاب کنید.' );
ok( 'E: swap_intro replaces the first paragraph only', 0 === mb_strpos( $swapped, '<p>فرش دستباف را بر اساس فضا، تردد و دکوراسیون انتخاب کنید.</p>' ) && false !== mb_strpos( $swapped, '<h2>کاربرد فرش</h2>' ), '' );
$prepended = Haa_Pipeline::swap_intro( '<h2>بدون مقدمه</h2>', 'مقدمه‌ی تازه.' );
ok( 'E: no paragraph means the intro is prepended', 0 === mb_strpos( $prepended, '<p>مقدمه‌ی تازه.</p>' ), '' );

/* ===================================================================
 * F. links: generic lone anchors are out, junk pages are out
 * =================================================================== */
echo "\n=== F. anchor discipline + linkable vetting ===\n";

ok( 'F: «راهنمای» is a generic anchor', Haa_Pipeline::is_generic_anchor_word( 'راهنمای' ), '' );
ok( 'F: «خرید» is a generic anchor', Haa_Pipeline::is_generic_anchor_word( 'خرید' ), '' );
ok( 'F: «های» is a generic anchor', Haa_Pipeline::is_generic_anchor_word( 'های' ), '' );
ok( 'F: «فرش» is a subject word, not generic', ! Haa_Pipeline::is_generic_anchor_word( 'فرش' ), '' );
ok( 'F: «دستباف» is a subject word', ! Haa_Pipeline::is_generic_anchor_word( 'دستباف' ), '' );

$ref = new ReflectionMethod( 'Haa_Pipeline', 'anchor_needle' );
$ref->setAccessible( true );

$body_full = '<p>برای انتخاب درست، راهنمای خرید فرش دستباف ما را هم ببینید.</p>';
ok( 'F: the full anchor wins when present', 'راهنمای خرید فرش دستباف' === $ref->invoke( null, 'راهنمای خرید فرش دستباف', $body_full ), '' );

$body_generic = '<p>این راهنمای را با دقت بخوانید.</p>';
ok( 'F: a two-word anchor never collapses to «راهنمای»', '' === $ref->invoke( null, 'راهنمای خرید', $body_generic ), '' );

$body_short = '<p>فرش را تمیز نگه دارید.</p>';
ok( 'F: a 4-char lone word is never an anchor', '' === $ref->invoke( null, 'فرش', $body_short ), '' );

$body_real = '<p>نگهداری درست عمر فرش را چند برابر می‌کند.</p>';
ok( 'F: a meaningful lone word may anchor', 'نگهداری' === $ref->invoke( null, 'نگهداری', $body_real ), '' );

$body_tail = '<p>به فرش دستباف توجه ویژه داشته باشید.</p>';
ok( 'F: a 4-word anchor may fall back to its tail', 'فرش دستباف' === $ref->invoke( null, 'راهنمای نگهداری فرش دستباف', $body_tail ), '' );

$GLOBALS['posts'][] = array( 'id' => 501, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' );
$GLOBALS['posts'][] = array( 'id' => 502, 'title' => 'راهنمای نگهداری فرش دستباف', 'type' => 'post', 'status' => 'publish' );
$GLOBALS['posts'][] = array( 'id' => 503, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' );
ok( 'F: a contact page is not a reading suggestion', ! Haa_Content::can_link( 501 ), '' );
ok( 'F: an about page is not either', ! Haa_Content::can_link( 503 ), '' );
ok( 'F: a real article is linkable', Haa_Content::can_link( 502 ), '' );

/* ===================================================================
 * G. write_section() end to end: one continuation, clean seam
 * =================================================================== */
echo "\n=== G. write_section() truncation repair, live behaviour ===\n";

$aw_topic = array(
        'id'      => 910,
        'title'   => 'چگونه فرش دستباف مناسب خانه انتخاب کنیم؟',
        'keyword' => 'فرش دستباف',
        'intent'  => 'commercial',
        'brief'   => array(),
        'reason'  => $live_reason,
);
$aw_sec = array( 'h2' => 'جنس الیاف و ساختار فرش دستباف', 'focus' => '', 'h3' => array() );
$aw_art = array( 'target_words' => 1600, 'max_sections' => 8, 'default_tone' => '', 'default_audience' => '' );

$GLOBALS['api_calls']      = array();
$GLOBALS['api_truncated']  = false;
$GLOBALS['api_replies']    = array( $cut_live ); // the section call
Haa_API::$reply            = 'و درخشان است تا پاک‌کردن لکه آسان بماند.'; // the continuation call falls through to the canned reply

$out = Haa_Pipeline::write_section( $aw_topic, $aw_sec, array(), $aw_art, array() );
ok( 'G: exactly one extra call was made', 2 === count( $GLOBALS['api_calls'] ), count( $GLOBALS['api_calls'] ) . ' calls' );
ok( 'G: the seam completes the cut sentence', false !== mb_strpos( $out, 'فرشی با بافت یکدست و درخشان است' ) && 'پاک‌کردن لکه آسان بماند.' === mb_substr( $out, -24 ), mb_substr( $out, -70 ) );
ok( 'G: the section prompt bans meta labels in-text', false !== mb_strpos( (string) $GLOBALS['api_calls'][0]['messages'][0]['content'], 'عنوان سئو، توضیحات متا، سلاگ یا جدول پژوهش در متن نمی' ), '' );

$GLOBALS['api_calls']     = array();
$GLOBALS['api_truncated'] = false;
$GLOBALS['api_replies']   = array( 'جنس الیاف را با محل استفاده بسنجید. بافت کوتاه برای تردد بالا مناسب‌تر است.' );
Haa_API::$reply           = 'نباید اضافه شود.';
$out2                     = Haa_Pipeline::write_section( $aw_topic, $aw_sec, array(), $aw_art, array() );
ok( 'G: a clean, at-budget section buys no continuation', 1 === count( $GLOBALS['api_calls'] ), count( $GLOBALS['api_calls'] ) . ' calls' );
ok( 'G: no canned continuation leaked in', false === mb_strpos( $out2, 'نباید اضافه شود' ), '' );

/* ===================================================================
 * H. wiring contracts
 * =================================================================== */
echo "\n=== H. wiring ===\n";

$pipe = file_get_contents( __DIR__ . '/../includes/class-haa-pipeline.php' );
$cont = file_get_contents( __DIR__ . '/../includes/class-haa-content.php' );
$mod  = file_get_contents( __DIR__ . '/../modules/class-haa-module-article-writer.php' );

ok( 'H: run() swaps in the meta intro', false !== mb_strpos( $pipe, 'self::swap_intro( $merged, $intro )' ), '' );
ok( 'H: sections are marker-stripped at birth', false !== mb_strpos( $pipe, "list( \$text, ) = Haa_Content::strip_meta_markers( \$text );" ), '' );
ok( 'H: the continuation op is named for the logs', false !== mb_strpos( $pipe, "'operation'  => 'section_continue'" ), '' );
ok( 'H: the outline prompt carries the page type', false !== mb_strpos( $pipe, 'نوع صفحه' ) && false !== mb_strpos( $pipe, 'page_type_guide' ), '' );
ok( 'H: buying-guide structure line exists', false !== mb_strpos( $pipe, 'راهنمای خرید: بخش‌ها به تصمیم خرید می‌رسند' ), '' );
ok( 'H: the outline bans intro/conclusion sections', false !== mb_strpos( $pipe, '«مقدمه» و «نتیجه‌گیری» در طرح نمی' ), '' );
ok( 'H: the meta prompt demands honest meta', false !== mb_strpos( $pipe, 'ادعای تأییدنشده نمی‌آید؛ دقیق و هماهنگ با متن بنویس' ), '' );
ok( 'H: the index path vets targets through internal_links()', false !== mb_strpos( $pipe, 'Haa_Content::internal_links(' ), '' );
ok( 'H: internal_links() owns the blacklist and the destination order', false !== mb_strpos( $cont, 'self::is_linkable(' ) && false !== mb_strpos( $cont, 'self::link_types()' ), '' );
ok( 'H: the section prompt ends sentences cleanly', false !== mb_strpos( $pipe, 'جمله‌ی آخر را کامل تمام کن' ), '' );
ok( 'H: the module scrubs the single-call output too', false !== mb_strpos( $mod, 'Haa_Content::strip_meta_markers( (string) $raw )' ), '' );
ok( 'H: editor_core is wired into style_suffix', false !== mb_strpos( $cont, '$out .= "\\n" . self::editor_core();' ), '' );

/* ===================================================================
 * I. version pins
 * =================================================================== */
echo "\n=== I. version ===\n";

$main = file_get_contents( __DIR__ . '/../hami-ai-assistant.php' );
ok( 'I: header says 2.1.56', false !== mb_strpos( $main, ' * Version:           2.1.56' ), '' );
ok( 'I: HAAI_VERSION is 2.1.56', false !== mb_strpos( $main, "define( 'HAAI_VERSION', '2.1.56' );" ), '' );

echo "\n";
if ( $fail ) {
        echo "SUITE 42 FAILURES: {$fail}\n";
        exit( 1 );
}
echo "SUITE 42 GREEN — editor core, marker scrub, truncation repair, intro swap, link discipline\n";
