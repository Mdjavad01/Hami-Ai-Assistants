<?php
/**
 * Contract every feature module implements.
 *
 * Modules must never reach into each other — they share only the core
 * services (Settings, API, DB, Usage, Queue) plus whatever the core exposes.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Haa_Module_Interface {

	/** @return string Stable slug, also the settings section key. */
	public function slug();

	/** @return string Human label (Persian). */
	public function label();

	/** @return string One-line description shown on the Modules screen. */
	public function description();

	/** @return bool Whether WooCommerce is required and present. */
	public function requires_woocommerce();

	/**
	 * Register hooks. Only called when the module is switched on, so a
	 * disabled module costs nothing at all — not even an add_action().
	 */
	public function register();

	/** Create the module's tables / options on activation. */
	public function activate();

	/** Remove cron and transient state. Data is removed by uninstall.php. */
	public function deactivate();
}
