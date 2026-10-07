<?php
/**
 * Plugin Name:          Customer History by Currency for WooCommerce
 * Plugin URI:           https://github.com/danielk-am/customer-history-by-currency-for-woocommerce
 * Description:          Shows a customer's order totals and averages per currency in the Customer history box on the edit order screen, instead of one total that adds different currencies together.
 * Version:              1.0.0
 * Requires at least:    6.9
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Daniel Kam
 * Author URI:           https://danielk.am
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          customer-history-by-currency-for-woocommerce
 * WC requires at least: 10.8
 * WC tested up to:      11.1
 *
 * @package DanielKam\CustomerHistoryByCurrency
 */

declare( strict_types=1 );

namespace DanielKam\CustomerHistoryByCurrency;

defined( 'ABSPATH' ) || exit;

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
