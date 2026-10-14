<?php
/**
 * Plugin Name:          Alkamy Customer History by Currency for WooCommerce
 * Plugin URI:           https://github.com/danielk-am/customer-history-by-currency-for-woocommerce
 * Description:          Shows a customer's order totals and averages per currency in the Customer history box on the edit order screen, instead of one total that adds different currencies together.
 * Version:              1.0.2
 * Requires at least:    6.9
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Alkamy
 * Author URI:           https://alkamy.ltd
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          alkamy-customer-history-by-currency-for-woocommerce
 * WC requires at least: 10.8
 * WC tested up to:      11.1
 *
 * @package DanielKam\CustomerHistoryByCurrency
 */

declare( strict_types=1 );

namespace DanielKam\CustomerHistoryByCurrency;

defined( 'ABSPATH' ) || exit;

// The original GitHub package has a different install path but shares this class.
// Check active plugins before loading it, regardless of WordPress's plugin load order.
$chbc_legacy_plugin = 'customer-history-by-currency-for-woocommerce/customer-history-by-currency-for-woocommerce.php';
if ( in_array( $chbc_legacy_plugin, (array) get_option( 'active_plugins', array() ), true ) || isset( get_site_option( 'active_sitewide_plugins', array() )[ $chbc_legacy_plugin ] ) || class_exists( Customer_History::class, false ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Deactivate the original Customer History by Currency for WooCommerce plugin to use the Alkamy version. Your orders and customer history will remain in WooCommerce.', 'alkamy-customer-history-by-currency-for-woocommerce' ) . '</p></div>';
			}
		}
	);
	return;
}

require_once __DIR__ . '/includes/class-customer-history.php';

// This plugin reads orders only from the order tables, so it is compatible with High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'WooCommerce' ) ) {
			Customer_History::instance()->init();
		}
	}
);
