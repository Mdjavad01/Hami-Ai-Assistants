<?php
/**
 * Module registry and lazy loader.
 *
 * A module's file is only required when it is enabled, and its hooks are only
 * registered in that same case. Switching a module off therefore costs zero
 * queries and zero closures on the next request.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Modules {

	/** @var Haa_Modules|null */
	private static $instance = null;

	/** @var array<string,Haa_Module_Interface> */
	private $loaded = array();

	/**
	 * @return Haa_Modules
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Catalogue: slug => class name. Order matters — the Modules screen and the
	 * dashboard render them in this order.
	 *
	 * @return array<string,string>
	 */
	public static function catalog() {
		return array(
			'chatbot'         => 'Haa_Module_Chatbot',
			'product-content' => 'Haa_Module_Product_Content',
			'article-writer'  => 'Haa_Module_Article_Writer',
			'autopilot'       => 'Haa_Module_Autopilot',
			'leads'           => 'Haa_Module_Leads',
			'discount'        => 'Haa_Module_Discount',
			'comments'        => 'Haa_Module_Comments',
			'semantic-search' => 'Haa_Module_Semantic_Search',
			// D4: three independent, switch-off-able content modules.
			'news'            => 'Haa_Module_News',
			'video-article'   => 'Haa_Module_Video_Article',
			'programmatic'    => 'Haa_Module_Programmatic',
			// Reworks content that already exists (post/page/product).
			'rewrite'         => 'Haa_Module_Rewrite',
		);
	}

	/**
	 * Static metadata for every module (label / description / WooCommerce
	 * requirement) so the Modules screen and dashboard never have to
	 * instantiate — or even require — a file for a disabled module.
	 * Keep in sync with each module's label()/description()/requires_woocommerce().
	 *
	 * @return array<string,array{label:string,description:string,woo:bool}>
	 */
	public static function catalog_meta() {
		return array(
			'chatbot'         => array(
				'label'       => __( 'چت‌بات فروش', 'hami-ai-assistant' ),
				'description' => __( 'دستیار گفتگوی شناور که از محتوای سایت جواب می‌دهد، لید جمع می‌کند و بدون توجه به کش صفحات کار می‌کند.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'product-content' => array(
				'label'       => __( 'محتوای محصول', 'hami-ai-assistant' ),
				'description' => __( 'توضیح سئو، عنوان، اسلگ، سؤالات متداول و تصویر محصول — با یک کلیک و قبل از انتشار.', 'hami-ai-assistant' ),
				'woo'         => true,
			),
			'article-writer'  => array(
				'label'       => __( 'مقاله‌نویس', 'hami-ai-assistant' ),
				'description' => __( 'مقاله‌های سئوشده را در صف می‌گذارد و یکی‌یکی به‌صورت پیش‌نویس می‌سازد؛ هر مقاله می‌تواند تصویر شاخص هم داشته باشد.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'autopilot'       => array(
				'label'       => __( 'اتوپایلوت', 'hami-ai-assistant' ),
				'description' => __( 'کمپین‌های زمان‌بندی‌شده که خودشان مقاله می‌سازند و منتشر می‌کنند؛ با سقف روزانه برای توکن و تعداد پست.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'leads'           => array(
				'label'       => __( 'باشگاه لید', 'hami-ai-assistant' ),
				'description' => __( 'مشتری‌هایی که در چت شماره داده‌اند را جمع می‌کند، امتیاز و دما می‌دهد، یادداشت و پیگیری ثبت می‌کند و به CSV خروجی می‌دهد.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'discount'        => array(
				'label'       => __( 'مذاکره‌گر تخفیف', 'hami-ai-assistant' ),
				'description' => __( 'در چت چانه می‌زند و در صورت توافق یک کد تخفیف واقعی ووکامرس صادر می‌کند؛ همه چیز در سقفی که خودتان تعیین کرده‌اید.', 'hami-ai-assistant' ),
				'woo'         => true,
			),
			'comments'        => array(
				'label'       => __( 'دیدگاه‌ها', 'hami-ai-assistant' ),
				'description' => __( 'برای دیدگاه‌های تازه یک پاسخ پیش‌نویس می‌نویسد؛ هیچ‌چیز بدون تأیید شما منتشر نمی‌شود.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'semantic-search' => array(
				'label'       => __( 'جست‌وجوی هوشمند', 'hami-ai-assistant' ),
				'description' => __( 'ایندکس ترکیبی روی محصولات و مطالب؛ جست‌وجو را به کدهای سئو و امبدینگ‌های واقعی مجهز می‌کند.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'news'              => array(
				'label'       => __( 'اخبار', 'hami-ai-assistant' ),
				'description' => __( 'فیدهای RSS را می‌خواند و برای هر خبر یک خلاصه و تحلیل تازه می‌نویسد؛ منبع و لینک ذکر می‌شود و متن کپی نمی‌شود.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'video-article'     => array(
				'label'       => __( 'ویدیو به مقاله', 'hami-ai-assistant' ),
				'description' => __( 'از ترنسکریپت SRT/VTT یک مقاله‌ی ساختارمند می‌سازد. فایل را خودتان می‌دهید؛ ما به یوتیوب دست نمی‌زنیم.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'programmatic'      => array(
				'label'       => __( 'صفحه‌سازی برنامه‌ریزی‌شده', 'hami-ai-assistant' ),
				'description' => __( 'از CSV یا ویژگی‌های ووکامرس، صفحه‌های سئوشده می‌سازد — با نگهبان «محتوای نازک» و صفحه‌ی hub برای لینک‌دهی.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
			'rewrite'           => array(
				'label'       => __( 'بازنویسی با AI', 'hami-ai-assistant' ),
				'description' => __( 'نوشته، برگه یا محصول موجود را بازنویسی می‌کند: پیش‌نمایش قابل ویرایش، و به‌روزرسانی فقط بعد از تأیید — با دکمه‌ای در فهرست و ویرایشگر خودشان.', 'hami-ai-assistant' ),
				'woo'         => false,
			),
		);
	}

	/**
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function is_enabled( $slug ) {
		return Haa_Settings::is_module_enabled( $slug );
	}

	/**
	 * Does this module need WooCommerce?
	 *
	 * This is the SINGLE source of truth for the dependency: it is derived
	 * from catalog_meta(), which the admin screen and the loader both read
	 * without instantiating (or even requiring) a module file. Each module's
	 * own `requires_woocommerce()` simply delegates here, so the two can never
	 * drift apart.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function requires_woocommerce( $slug ) {
		$meta = self::catalog_meta();
		return isset( $meta[ $slug ] ) && ! empty( $meta[ $slug ]['woo'] );
	}

	/**
	 * Instantiate and register every enabled module.
	 */
	public function register_enabled() {
		foreach ( self::catalog() as $slug => $class ) {
			if ( ! Haa_Settings::is_module_enabled( $slug ) ) {
				continue;
			}
			$module = $this->load( $slug, $class );
			if ( $module ) {
				$module->register();
			}
		}
	}

	/**
	 * Load one module instance (its file is required on demand).
	 *
	 * @param string $slug  Slug.
	 * @param string $class Class name.
	 * @return Haa_Module_Interface|null
	 */
	private function load( $slug, $class ) {
		if ( isset( $this->loaded[ $slug ] ) ) {
			return $this->loaded[ $slug ];
		}
		// A WooCommerce-dependent module stays fully dormant while WooCommerce is
		// inactive: its file is not required, it is not instantiated and none of
		// its hooks are registered. The stored "on" flag is left untouched, so
		// the module wakes up on its own the moment WooCommerce is active again.
		if ( self::requires_woocommerce( $slug ) && ! haa_is_woo() ) {
			return null;
		}
		if ( ! class_exists( $class, true ) ) {
			return null;
		}
		$instance = new $class();
		if ( ! $instance instanceof Haa_Module_Interface ) {
			return null;
		}
		$this->loaded[ $slug ] = $instance;
		return $instance;
	}

	/**
	 * Every loaded module instance.
	 *
	 * @return array<string,Haa_Module_Interface>
	 */
	public function loaded() {
		return $this->loaded;
	}

	/**
	 * Full catalogue with live metadata, for the admin screens.
	 * Reads from catalog_meta() — never requires a module file.
	 *
	 * @return array<int,array{slug:string,label:string,description:string,enabled:bool,woo:bool,available:bool,missing_woocommerce:bool}>
	 */
	public static function describe_all() {
		$out             = array();
		$enabled_modules = Haa_Settings::enabled_modules();
		$meta_map        = self::catalog_meta();
		foreach ( self::catalog() as $slug => $class ) {
			$enabled = ! empty( $enabled_modules[ $slug ] );
			$meta    = $meta_map[ $slug ] ?? array( 'label' => $slug, 'description' => '', 'woo' => false );
			// 'woo' comes from the single source (requires_woocommerce()), not a
			// second read that could disagree with it.
			$needs_woo = self::requires_woocommerce( $slug );
			$out[]   = array(
				'slug'                => $slug,
				'label'               => $meta['label'],
				'description'         => $meta['description'],
				'enabled'             => (bool) $enabled,
				'woo'                 => $needs_woo,
				'available'           => ! $needs_woo || haa_is_woo(),
				// Read by the Modules screen ($blocked) and the dashboard badge.
				'missing_woocommerce' => $needs_woo && ! haa_is_woo(),
			);
		}
		return $out;
	}

	/**
	 * Activation: let each module create its own tables.
	 */
	public static function activate_all() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		Haa_DB::create();

		foreach ( self::catalog() as $slug => $class ) {
			if ( ! class_exists( $class, true ) ) {
				continue;
			}
			/** @var Haa_Module_Interface $module */
			$module = new $class();
			$module->activate();
		}

		// Recreate after activation so newly-required module tables appear.
		Haa_DB::create();
	}

	/**
	 * @param string $slug Module slug.
	 */
	public static function deactivate_module( $slug ) {
		$class = self::catalog()[ $slug ] ?? '';
		if ( $class && class_exists( $class, true ) ) {
			/** @var Haa_Module_Interface $module */
			$module = new $class();
			$module->deactivate();
		}
	}

	/**
	 * @param string $slug Module slug.
	 */
	public static function activate_module( $slug ) {
		$class = self::catalog()[ $slug ] ?? '';
		if ( $class && class_exists( $class, true ) ) {
			/** @var Haa_Module_Interface $module */
			$module = new $class();
			$module->activate();
		}
	}
}
