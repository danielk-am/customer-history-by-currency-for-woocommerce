<?php
/**
 * Test bootstrap. Runs the tests inside WooCommerce's own PHPUnit environment.
 *
 * Needs a WooCommerce development checkout with its Composer dependencies installed:
 * WC_PLUGIN_DIR=/path/to/woocommerce/plugins/woocommerce
 * WP_TESTS_DIR=/path/to/wordpress-tests-lib
 *
 * @package DanielKam\CustomerHistoryByCurrency
 */

$chbc_wc_dir    = rtrim( (string) getenv( 'WC_PLUGIN_DIR' ), '/' );
$chbc_tests_dir = rtrim( (string) getenv( 'WP_TESTS_DIR' ), '/' );

if ( '' === $chbc_wc_dir || ! file_exists( $chbc_wc_dir . '/tests/legacy/bootstrap.php' ) ) {
	fwrite( STDERR, "Set WC_PLUGIN_DIR to plugins/woocommerce in a WooCommerce development checkout.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

if ( '' === $chbc_tests_dir || ! file_exists( $chbc_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to the WordPress PHPUnit test library.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once $chbc_tests_dir . '/includes/functions.php';

// Load this plugin after WooCommerce, as WordPress would.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/customer-history-by-currency-for-woocommerce.php';
	},
	20
);

require $chbc_wc_dir . '/tests/legacy/bootstrap.php';
