<?php
/**
 * Tests for the per-currency Customer history box.
 *
 * @package DanielKam\CustomerHistoryByCurrency
 */

declare( strict_types=1 );

namespace DanielKam\CustomerHistoryByCurrency\Tests;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Admin\Orders\MetaBoxes\CustomerHistory as WooCommerceCustomerHistory;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use DanielKam\CustomerHistoryByCurrency\Customer_History;
use DOMDocument;
use DOMXPath;
use WC_Helper_Order;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Renders WooCommerce's own Customer history box with this plugin active.
 */
class CustomerHistoryTest extends WC_Unit_Test_Case {

	/**
	 * WooCommerce's meta box, which the edit order screen calls.
	 *
	 * @var WooCommerceCustomerHistory
	 */
	private $box;

	/**
	 * Whether a test switched High-Performance Order Storage off.
	 *
	 * @var bool
	 */
	private $restore_hpos = false;

	/**
	 * Switches on the order tables and sets the store currency to EUR.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		OrderHelper::create_order_custom_table_if_not_exist();
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			OrderHelper::toggle_cot_feature_and_usage( true );
		}

		update_option( 'woocommerce_currency', 'EUR' );
		$this->box = new WooCommerceCustomerHistory();
	}

	/**
	 * Restores the order tables and clears the order being edited.
	 */
	public function tearDown(): void {
		if ( $this->restore_hpos ) {
			OrderHelper::toggle_cot_feature_and_usage( true );
			$this->restore_hpos = false;
		}
		unset( $GLOBALS['theorder'] );
		parent::tearDown();
	}

	/**
	 * @testdox Lists a total and an average per currency for a customer with orders in several currencies.
	 *
	 * @testWith ["registered"]
	 *           ["guest"]
	 *
	 * @param string $customer_type Whether the orders belong to a registered customer or a guest.
	 */
	public function test_mixed_currency_orders_are_listed_per_currency( string $customer_type ): void {
		$customer_id = 'registered' === $customer_type ? $this->factory->user->create() : 0;
		$email       = 'mixed-currencies@example.com';

		$order = $this->create_order( $customer_id, 'GBP', 36.00, $email );
		$this->create_order( $customer_id, 'EUR', 41.90, $email );
		$this->create_order( $customer_id, 'EUR', 10.00, $email );
		$this->create_order( $customer_id, 'AUD', 20.00, $email );

		$output = $this->render_box( $order );

		$this->assertSame( '4', self::text( $output, 'order-attribution-total-orders' ), 'Every order is still counted.' );
		$this->assertStringNotContainsString( '107.90', $output, 'Different currencies are no longer added together.' );
		$this->assertSame(
			array(
				'EUR' => '€51.90 EUR (2 orders)',
				'AUD' => '$20.00 AUD (1 order)',
				'GBP' => '£36.00 GBP (1 order)',
			),
			self::rows( $output, 'order-attribution-total-spend' ),
			'One total per currency, store currency first, each with its own order count.'
		);
		$this->assertSame(
			array(
				'EUR' => '€25.95 EUR',
				'AUD' => '$20.00 AUD',
				'GBP' => '£36.00 GBP',
			),
			self::rows( $output, 'order-attribution-average-order-value' ),
			'Each average divides a currency total by the orders in that currency.'
		);
	}

	/**
	 * @testdox Takes a refund off the total of the refunded order's currency only.
	 */
	public function test_refund_reduces_only_its_own_currency(): void {
		$customer_id = $this->factory->user->create();
		$order       = $this->create_order( $customer_id, 'EUR', 100.00 );
		$foreign     = $this->create_order( $customer_id, 'GBP', 50.00 );

		wc_create_refund(
			array(
				'order_id' => $foreign->get_id(),
				'amount'   => 20.00,
			)
		);

		$this->assertSame(
			array(
				'EUR' => '€100.00 EUR (1 order)',
				'GBP' => '£30.00 GBP (1 order)',
			),
			self::rows( $this->render_box( $order ), 'order-attribution-total-spend' )
		);
	}

	/**
	 * @testdox Leaves the box byte for byte as WooCommerce renders it when every order is in the store currency.
	 */
	public function test_store_currency_customer_is_left_untouched(): void {
		$customer_id = $this->factory->user->create();
		$order       = $this->create_order( $customer_id, 'EUR', 25.00 );
		$this->create_order( $customer_id, 'EUR', 75.00 );

		$with_plugin    = $this->render_box( $order );
		$without_plugin = $this->render_box_without_plugin( $order );

		$this->assertSame( $without_plugin, $with_plugin );
		$this->assertStringContainsString( '100.00', $with_plugin, 'The single total is still shown.' );
	}

	/**
	 * @testdox Counts orders whose stored currency is missing, or is the store currency in another letter case or with spaces, towards the store currency.
	 *
	 * @testWith [null]
	 *           [""]
	 *           ["eur"]
	 *           ["EUR "]
	 *
	 * @param string|null $stored_currency The value stored in the currency column of one order.
	 */
	public function test_loosely_stored_store_currency_is_totalled_with_the_store_currency( ?string $stored_currency ): void {
		$customer_id = $this->factory->user->create();

		// The loosely stored order comes first, so a database that groups it with the clean one reports its spelling.
		$this->store_currency( $this->create_order( $customer_id, 'EUR', 10.00 ), $stored_currency );
		$order = $this->create_order( $customer_id, 'EUR', 40.00 );
		$this->create_order( $customer_id, 'GBP', 36.00 );

		$this->assertSame(
			array(
				'EUR' => '€50.00 EUR (2 orders)',
				'GBP' => '£36.00 GBP (1 order)',
			),
			self::rows( $this->render_box( $order ), 'order-attribution-total-spend' )
		);
	}

	/**
	 * @testdox Gives a currency code that is not three letters long its own line.
	 */
	public function test_four_letter_currency_code_gets_its_own_line(): void {
		$customer_id = $this->factory->user->create();
		$order       = $this->create_order( $customer_id, 'EUR', 40.00 );
		$this->store_currency( $this->create_order( $customer_id, 'EUR', 25.00 ), 'USDT' );

		$this->assertSame(
			array(
				'EUR'  => '€40.00 EUR (1 order)',
				'USDT' => '25.00 USDT (1 order)',
			),
			self::rows( $this->render_box( $order ), 'order-attribution-total-spend' )
		);
	}

	/**
	 * @testdox Shows the order currency when a customer's only currency is not the store currency.
	 */
	public function test_customer_with_only_a_foreign_currency_shows_that_currency(): void {
		$customer_id = $this->factory->user->create();
		$order       = $this->create_order( $customer_id, 'GBP', 36.00 );
		$output      = $this->render_box( $order );

		$this->assertSame( array( 'GBP' => '£36.00 GBP' ), self::rows( $output, 'order-attribution-total-spend' ), 'The total is in pounds, with its code and no order count.' );
		$this->assertSame( array( 'GBP' => '£36.00 GBP' ), self::rows( $output, 'order-attribution-average-order-value' ), 'The average is in pounds, with its code.' );
	}

	/**
	 * @testdox Leaves the box alone when its numbers do not add up to the count WooCommerce calculated.
	 */
	public function test_box_is_left_alone_when_the_numbers_do_not_add_up(): void {
		$order = $this->create_mixed_currency_customer();

		$this->assertSame(
			$this->render_template_without_plugin( 99, 87.90, 29.30 ),
			$this->render_template( $order, 99, 87.90, 29.30 )
		);
	}

	/**
	 * @testdox Stands down when WooCommerce passes per-currency totals itself.
	 */
	public function test_stands_down_when_woocommerce_lists_currencies_itself(): void {
		$order = $this->create_mixed_currency_customer();

		$this->assertSame(
			$this->render_template_without_plugin( 3, 87.90, 29.30 ),
			$this->render_template( $order, 3, 87.90, 29.30, array( 'totals_per_currency' => array() ) )
		);
	}

	/**
	 * @testdox Leaves the box alone when no order is being edited.
	 */
	public function test_box_is_left_alone_without_a_current_order(): void {
		$this->create_mixed_currency_customer();

		$this->assertSame(
			$this->render_template_without_plugin( 3, 87.90, 29.30 ),
			$this->render_template( null, 3, 87.90, 29.30 )
		);
	}

	/**
	 * @testdox Leaves the box alone on legacy order storage.
	 */
	public function test_box_is_left_alone_on_legacy_order_storage(): void {
		$order = $this->create_mixed_currency_customer();

		$this->restore_hpos = true;
		OrderHelper::toggle_cot_feature_and_usage( false );

		$this->assertSame(
			$this->render_template_without_plugin( 3, 87.90, 29.30 ),
			$this->render_template( $order, 3, 87.90, 29.30 )
		);
	}

	/**
	 * @testdox Lists the same customer per currency once the numbers add up, as a control for the tests above.
	 */
	public function test_template_is_adjusted_when_the_numbers_add_up(): void {
		$order = $this->create_mixed_currency_customer();

		$this->assertSame(
			array(
				'EUR' => '€51.90 EUR (2 orders)',
				'GBP' => '£36.00 GBP (1 order)',
			),
			self::rows( $this->render_template( $order, 3, 87.90, 29.30 ), 'order-attribution-total-spend' )
		);
	}

	/**
	 * @testdox Puts the store currency first and sorts the other currencies by code.
	 */
	public function test_group_by_currency_orders_the_store_currency_first(): void {
		$rows = array(
			(object) array(
				'currency'     => 'ZAR',
				'orders_count' => 1,
				'total_spend'  => '5.00',
			),
			(object) array(
				'currency'     => 'usd',
				'orders_count' => 2,
				'total_spend'  => '7.50',
			),
			(object) array(
				'currency'     => 'AUD',
				'orders_count' => 1,
				'total_spend'  => '3.00',
			),
			(object) array(
				'currency'     => null,
				'orders_count' => 1,
				'total_spend'  => '2.50',
			),
		);

		$this->assertSame(
			array(
				'USD' => array(
					'count' => 3,
					'total' => 10.0,
				),
				'AUD' => array(
					'count' => 1,
					'total' => 3.0,
				),
				'ZAR' => array(
					'count' => 1,
					'total' => 5.0,
				),
			),
			Customer_History::group_by_currency( $rows, 'USD', array( 'AUD', 'USD', 'ZAR' ) )
		);
	}

	/**
	 * Creates a customer with completed orders of EUR 41.90, EUR 10.00 and GBP 36.00.
	 *
	 * @return WC_Order The first order.
	 */
	private function create_mixed_currency_customer(): WC_Order {
		$customer_id = $this->factory->user->create();
		$order       = $this->create_order( $customer_id, 'EUR', 41.90 );
		$this->create_order( $customer_id, 'EUR', 10.00 );
		$this->create_order( $customer_id, 'GBP', 36.00 );

		return $order;
	}

	/**
	 * Creates a completed order.
	 *
	 * @param int    $customer_id   The customer user ID, or 0 for a guest.
	 * @param string $currency      The order currency code.
	 * @param float  $total         The order total.
	 * @param string $billing_email Optional billing email, which guest orders are matched on.
	 * @return WC_Order The saved order.
	 */
	private function create_order( int $customer_id, string $currency, float $total, string $billing_email = '' ): WC_Order {
		$order = WC_Helper_Order::create_order( $customer_id );
		$order->set_status( OrderStatus::COMPLETED );
		$order->set_currency( $currency );
		$order->set_total( $total );
		if ( '' !== $billing_email ) {
			$order->set_billing_email( $billing_email );
		}
		$order->save();

		return $order;
	}

	/**
	 * Overwrites the stored currency of an order, bypassing WC_Order::set_currency().
	 *
	 * @param WC_Order    $order    The order to change.
	 * @param string|null $currency The value to store.
	 */
	private function store_currency( WC_Order $order, ?string $currency ): void {
		global $wpdb;

		$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'currency' => $currency ), array( 'id' => $order->get_id() ) );
	}

	/**
	 * Renders the box the way the edit order screen does.
	 *
	 * @param WC_Order $order The order being edited.
	 * @return string The rendered HTML.
	 */
	private function render_box( WC_Order $order ): string {
		$GLOBALS['theorder'] = $order;

		ob_start();
		try {
			$this->box->output( $order );
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	/**
	 * Renders the box with this plugin's hooks removed.
	 *
	 * @param WC_Order $order The order being edited.
	 * @return string The rendered HTML.
	 */
	private function render_box_without_plugin( WC_Order $order ): string {
		return $this->without_plugin(
			function () use ( $order ) {
				return $this->render_box( $order );
			}
		);
	}

	/**
	 * Loads WooCommerce's template with the given numbers, as WooCommerce would.
	 *
	 * @param WC_Order|null $order           The order being edited, if any.
	 * @param int           $orders_count    The order count WooCommerce calculated.
	 * @param float         $total_spend     The single total WooCommerce calculated.
	 * @param float         $avg_order_value The single average WooCommerce calculated.
	 * @param array         $extra           Extra template arguments.
	 * @return string The rendered HTML.
	 */
	private function render_template( ?WC_Order $order, int $orders_count, float $total_spend, float $avg_order_value, array $extra = array() ): string {
		$GLOBALS['theorder'] = $order;

		ob_start();
		try {
			wc_get_template(
				'order/customer-history.php',
				array_merge(
					array(
						'orders_count'    => $orders_count,
						'total_spend'     => $total_spend,
						'avg_order_value' => $avg_order_value,
						'tooltip'         => 'Tooltip text',
					),
					$extra
				)
			);
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	/**
	 * Loads WooCommerce's template with this plugin's hooks removed.
	 *
	 * @param int   $orders_count    The order count WooCommerce calculated.
	 * @param float $total_spend     The single total WooCommerce calculated.
	 * @param float $avg_order_value The single average WooCommerce calculated.
	 * @return string The rendered HTML.
	 */
	private function render_template_without_plugin( int $orders_count, float $total_spend, float $avg_order_value ): string {
		return $this->without_plugin(
			function () use ( $orders_count, $total_spend, $avg_order_value ) {
				return $this->render_template( null, $orders_count, $total_spend, $avg_order_value );
			}
		);
	}

	/**
	 * Runs a callback with this plugin's hooks removed, then restores them.
	 *
	 * @param callable $callback What to run.
	 * @return string What the callback returned.
	 */
	private function without_plugin( callable $callback ): string {
		$plugin = Customer_History::instance();
		remove_action( 'woocommerce_before_template_part', array( $plugin, 'start_capture' ), PHP_INT_MAX );
		remove_action( 'woocommerce_after_template_part', array( $plugin, 'finish_capture' ), -PHP_INT_MAX );

		try {
			return $callback();
		} finally {
			$plugin->init();
		}
	}

	/**
	 * Returns the visible text of the first element with the given class.
	 *
	 * @param string $html  The rendered box.
	 * @param string $class_name The class to look for.
	 * @return string The text, with whitespace collapsed.
	 */
	private static function text( string $html, string $class_name ): string {
		return self::xpath( $html )->evaluate( 'normalize-space(//*[' . self::has_class( $class_name ) . '])' );
	}

	/**
	 * Returns the visible text of each per-currency line inside the element with the given class.
	 *
	 * @param string $html  The rendered box.
	 * @param string $class_name The class of the element that holds the lines.
	 * @return array<string, string> Line text keyed by currency code, in display order.
	 */
	private static function rows( string $html, string $class_name ): array {
		$xpath = self::xpath( $html );
		$rows  = array();

		foreach ( $xpath->query( '//*[' . self::has_class( $class_name ) . ']//*[@data-currency]' ) as $row ) {
			$rows[ $row->getAttribute( 'data-currency' ) ] = $xpath->evaluate( 'normalize-space(.)', $row );
		}

		return $rows;
	}

	/**
	 * Returns an XPath condition matching elements that carry a class.
	 *
	 * @param string $class_name The class to match.
	 * @return string The condition.
	 */
	private static function has_class( string $class_name ): string {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class_name . ' ")';
	}

	/**
	 * Parses rendered HTML for XPath queries.
	 *
	 * @param string $html The rendered box.
	 * @return DOMXPath
	 */
	private static function xpath( string $html ): DOMXPath {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $document );
	}
}
