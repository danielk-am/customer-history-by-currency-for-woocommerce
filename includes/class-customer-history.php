<?php
/**
 * Lists the Customer history totals and averages per currency.
 *
 * @package DanielKam\CustomerHistoryByCurrency
 */

declare( strict_types=1 );

namespace DanielKam\CustomerHistoryByCurrency;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the single total and average in WooCommerce's Customer history box
 * with one line per currency, when a customer has orders in another currency.
 *
 * WooCommerce keeps rendering its own template. This class captures that output
 * and swaps only the two amounts. Whenever its numbers do not add up to the
 * ones WooCommerce calculated, it leaves the box exactly as WooCommerce drew it.
 */
final class Customer_History {

	/**
	 * The WooCommerce template this plugin adjusts.
	 */
	private const TEMPLATE = 'order/customer-history.php';

	/**
	 * The only instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * History and template arguments for the box being rendered, or null when it is left alone.
	 *
	 * @var array{history: array<string, array{count: int, total: float}>, total_spend: mixed, avg_order_value: mixed}|null
	 */
	private $pending = null;

	/**
	 * Get the only instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook into the rendering of WooCommerce templates.
	 *
	 * The buffer opens last and closes first, so it only ever holds the template itself.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_before_template_part', array( $this, 'start_capture' ), PHP_INT_MAX, 4 );
		add_action( 'woocommerce_after_template_part', array( $this, 'finish_capture' ), -PHP_INT_MAX, 1 );
	}

	/**
	 * Start capturing the Customer history template when this customer has orders in another currency.
	 *
	 * @param mixed $template_name Name of the template being loaded.
	 * @param mixed $template_path Path the template was looked up in. Unused.
	 * @param mixed $located       Full path of the template file. Unused.
	 * @param mixed $args          Arguments passed to the template.
	 * @return void
	 */
	public function start_capture( $template_name, $template_path = '', $located = '', $args = array() ): void {
		$this->pending = null;

		if ( self::TEMPLATE !== $template_name || ! is_array( $args ) ) {
			return;
		}

		// WooCommerce may list currencies itself one day. Stand down when it does.
		if ( isset( $args['totals_per_currency'] ) || ! isset( $args['orders_count'], $args['total_spend'], $args['avg_order_value'] ) ) {
			return;
		}

		// The Customer history box only reads the order tables when High-Performance Order Storage is on.
		if ( ! class_exists( OrderUtil::class ) || ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return;
		}

		global $theorder;
		if ( ! $theorder instanceof WC_Order ) {
			return;
		}

		$history = self::group_by_currency( $this->query_history( $theorder ), get_woocommerce_currency(), array_keys( get_woocommerce_currencies() ) );

		if ( ! self::has_another_currency( $history, get_woocommerce_currency() ) || ! self::adds_up_to( $history, (int) $args['orders_count'], (float) $args['total_spend'] ) ) {
			return;
		}

		$this->pending = array(
			'history'         => $history,
			'total_spend'     => $args['total_spend'],
			'avg_order_value' => $args['avg_order_value'],
		);

		ob_start();
	}

	/**
	 * Print the captured template with the amounts listed per currency.
	 *
	 * @param mixed $template_name Name of the template that was loaded.
	 * @return void
	 */
	public function finish_capture( $template_name ): void {
		if ( self::TEMPLATE !== $template_name || null === $this->pending ) {
			return;
		}

		$pending       = $this->pending;
		$this->pending = null;
		$html          = (string) ob_get_clean();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own escaped template output, with rows escaped in render_rows().
		echo self::replace_amounts( $html, $pending['history'], $pending['total_spend'], $pending['avg_order_value'] );
	}

	/**
	 * Swap the single total and average in the rendered box for one line per currency.
	 *
	 * Returns the HTML untouched unless both amounts are found where WooCommerce prints them.
	 *
	 * @param string                                         $html            The rendered Customer history template.
	 * @param array<string, array{count: int, total: float}> $history         Order count and total per currency.
	 * @param mixed                                          $total_spend     The single total WooCommerce printed.
	 * @param mixed                                          $avg_order_value The single average WooCommerce printed.
	 * @return string The adjusted HTML.
	 */
	public static function replace_amounts( string $html, array $history, $total_spend, $avg_order_value ): string {
		$with_totals = self::replace_after( $html, 'class="order-attribution-total-spend"', wp_kses_post( wc_price( $total_spend ) ), self::render_rows( $history, 'total' ) );
		if ( null === $with_totals ) {
			return $html;
		}

		$with_averages = self::replace_after( $with_totals, 'class="order-attribution-average-order-value"', wp_kses_post( wc_price( $avg_order_value ) ), self::render_rows( $history, 'average' ) );

		return $with_averages ?? $html;
	}

	/**
	 * Replace the first occurrence of a string that follows a marker.
	 *
	 * @param string $html        The HTML to search.
	 * @param string $marker      Text that comes before the string to replace.
	 * @param string $search      The string to replace.
	 * @param string $replacement What to put in its place.
	 * @return string|null The new HTML, or null when the marker or the string is missing.
	 */
	private static function replace_after( string $html, string $marker, string $search, string $replacement ): ?string {
		$marker_position = strpos( $html, $marker );
		if ( false === $marker_position || '' === $search ) {
			return null;
		}

		$position = strpos( $html, $search, $marker_position );
		if ( false === $position ) {
			return null;
		}

		return substr_replace( $html, $replacement, $position, strlen( $search ) );
	}

	/**
	 * Render one line per currency.
	 *
	 * @param array<string, array{count: int, total: float}> $history Order count and total per currency.
	 * @param string                                         $show    'total' for totals with order counts, 'average' for averages.
	 * @return string Escaped HTML.
	 */
	public static function render_rows( array $history, string $show ): string {
		$show_counts = 'total' === $show && count( $history ) > 1;
		$rows        = '';

		foreach ( $history as $currency => $currency_history ) {
			$currency = (string) $currency;
			$amount   = 'average' === $show ? $currency_history['total'] / $currency_history['count'] : $currency_history['total'];
			$orders   = '';

			if ( $show_counts ) {
				$orders = sprintf(
					' <small class="customer-history-by-currency__orders">%s</small>',
					esc_html(
						sprintf(
							/* translators: %s: number of orders in one currency */
							_n( '(%s order)', '(%s orders)', $currency_history['count'], 'customer-history-by-currency-for-woocommerce' ),
							number_format_i18n( $currency_history['count'] )
						)
					)
				);
			}

			$rows .= sprintf(
				'<span class="customer-history-by-currency__row" data-currency="%1$s" style="display:block">%2$s <span class="customer-history-by-currency__code">%3$s</span>%4$s</span>',
				esc_attr( $currency ),
				wp_kses_post( wc_price( $amount, array( 'currency' => $currency ) ) ),
				esc_html( $currency ),
				$orders
			);
		}

		return $rows;
	}

	/**
	 * Merge the database rows into one entry per currency, store currency first and the rest by code.
	 *
	 * Orders saved without a currency count towards the store currency, as WooCommerce shows them.
	 * Stored codes are matched to registered currencies whatever their letter case.
	 *
	 * @param array<int, object> $rows             Rows with currency, orders_count and total_spend properties.
	 * @param string             $store_currency   The store currency code.
	 * @param array<int, mixed>  $registered_codes Every registered currency code.
	 * @return array<string, array{count: int, total: float}> Order count and total per currency.
	 */
	public static function group_by_currency( array $rows, string $store_currency, array $registered_codes ): array {
		$codes_by_uppercase = array();
		foreach ( $registered_codes as $code ) {
			$codes_by_uppercase[ strtoupper( (string) $code ) ] = (string) $code;
		}

		$history = array();
		foreach ( $rows as $row ) {
			$count = (int) ( $row->orders_count ?? 0 );
			if ( $count < 1 ) {
				continue;
			}

			$currency = trim( (string) ( $row->currency ?? '' ) );
			$currency = '' === $currency ? $store_currency : ( $codes_by_uppercase[ strtoupper( $currency ) ] ?? $currency );

			$history[ $currency ] = array(
				'count' => ( $history[ $currency ]['count'] ?? 0 ) + $count,
				'total' => ( $history[ $currency ]['total'] ?? 0.0 ) + (float) ( $row->total_spend ?? 0 ),
			);
		}

		ksort( $history, SORT_STRING );
		if ( isset( $history[ $store_currency ] ) ) {
			$history = array( $store_currency => $history[ $store_currency ] ) + $history;
		}

		return $history;
	}

	/**
	 * Whether the history includes a currency other than the store currency.
	 *
	 * @param array<string, array{count: int, total: float}> $history        Order count and total per currency.
	 * @param string                                         $store_currency The store currency code.
	 * @return bool
	 */
	public static function has_another_currency( array $history, string $store_currency ): bool {
		return count( $history ) > 1 || ( 1 === count( $history ) && ! isset( $history[ $store_currency ] ) );
	}

	/**
	 * Whether the per-currency numbers add up to the count and total WooCommerce calculated.
	 *
	 * @param array<string, array{count: int, total: float}> $history      Order count and total per currency.
	 * @param int                                            $orders_count The order count WooCommerce shows.
	 * @param float                                          $total_spend  The single total WooCommerce shows.
	 * @return bool
	 */
	public static function adds_up_to( array $history, int $orders_count, float $total_spend ): bool {
		$counted = array_sum( array_column( $history, 'count' ) );
		$totaled = array_sum( array_column( $history, 'total' ) );

		return $counted === $orders_count && abs( $totaled - $total_spend ) < 0.005;
	}

	/**
	 * Count and total the customer's orders per currency, net of refunds.
	 *
	 * Matches the customer the same way WooCommerce does: by customer ID, or by billing email for a guest.
	 *
	 * @param WC_Order $order The order being viewed.
	 * @return array<int, object> Rows with currency, orders_count and total_spend properties.
	 */
	private function query_history( WC_Order $order ): array {
		global $wpdb;

		$customer_id   = (int) $order->get_customer_id();
		$billing_email = (string) $order->get_billing_email();
		$orders        = $wpdb->prefix . 'wc_orders';
		$addresses     = $wpdb->prefix . 'wc_order_addresses';
		$statuses      = self::get_excluded_statuses();
		$status_list   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		if ( $customer_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $status_list holds only %s placeholders.
			$sql = $wpdb->prepare(
				"SELECT filtered.currency AS currency,
					COUNT(*) AS orders_count,
					COALESCE( SUM( filtered.total_amount ), 0 ) + COALESCE( SUM( refunds.refund_total ), 0 ) AS total_spend
				FROM (
					SELECT id, total_amount, currency
					FROM %i
					WHERE customer_id = %d AND type = 'shop_order' AND status NOT IN ( $status_list )
				) AS filtered
				LEFT JOIN (
					SELECT refund.parent_order_id, SUM( refund.total_amount ) AS refund_total
					FROM %i AS refund
					INNER JOIN %i AS parent ON refund.parent_order_id = parent.id
					WHERE refund.type = 'shop_order_refund' AND parent.customer_id = %d AND parent.type = 'shop_order' AND parent.status NOT IN ( $status_list )
					GROUP BY refund.parent_order_id
				) AS refunds ON filtered.id = refunds.parent_order_id
				GROUP BY filtered.currency",
				array_merge( array( $orders, $customer_id ), $statuses, array( $orders, $orders, $customer_id ), $statuses )
			);
			// phpcs:enable
		} elseif ( '' !== $billing_email ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $status_list holds only %s placeholders.
			$sql = $wpdb->prepare(
				"SELECT filtered.currency AS currency,
					COUNT(*) AS orders_count,
					COALESCE( SUM( filtered.total_amount ), 0 ) + COALESCE( SUM( refunds.refund_total ), 0 ) AS total_spend
				FROM (
					SELECT o.id, o.total_amount, o.currency
					FROM %i AS o
					INNER JOIN %i AS address ON o.id = address.order_id AND address.address_type = 'billing'
					WHERE o.customer_id = 0 AND address.email = %s AND o.type = 'shop_order' AND o.status NOT IN ( $status_list )
				) AS filtered
				LEFT JOIN (
					SELECT refund.parent_order_id, SUM( refund.total_amount ) AS refund_total
					FROM %i AS refund
					INNER JOIN %i AS parent ON refund.parent_order_id = parent.id
					INNER JOIN %i AS parent_address ON parent.id = parent_address.order_id AND parent_address.address_type = 'billing'
					WHERE refund.type = 'shop_order_refund' AND parent.customer_id = 0 AND parent_address.email = %s AND parent.type = 'shop_order' AND parent.status NOT IN ( $status_list )
					GROUP BY refund.parent_order_id
				) AS refunds ON filtered.id = refunds.parent_order_id
				GROUP BY filtered.currency",
				array_merge( array( $orders, $addresses, $billing_email ), $statuses, array( $orders, $orders, $addresses, $billing_email ), $statuses )
			);
			// phpcs:enable
		} else {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above. The box shows live totals, as WooCommerce's own query does, and there is no API that totals orders per currency.
		$rows = $wpdb->get_results( $sql );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get the order statuses the Customer history box leaves out, as stored in the order tables.
	 *
	 * Follows the Analytics setting and filter WooCommerce uses for the same box.
	 *
	 * @return string[] Status values, never empty.
	 */
	private static function get_excluded_statuses(): array {
		$excluded = get_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'failed', 'cancelled' ) );
		$excluded = array_merge( array( 'auto-draft', 'trash' ), is_array( $excluded ) ? $excluded : array( 'pending', 'failed', 'cancelled' ) );

		/**
		 * WooCommerce's own filter for the order statuses that analytics and the Customer history box leave out.
		 *
		 * @since 1.0.0
		 * @param array $excluded Order statuses to exclude.
		 */
		$filtered = apply_filters( 'woocommerce_analytics_excluded_order_statuses', $excluded ); // phpcs:ignore WooCommerce.Commenting.CommentHooks, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook, applied so both use the same list.
		$excluded = is_array( $filtered ) ? $filtered : $excluded;

		$statuses = array();
		foreach ( $excluded as $status ) {
			$status = sanitize_title( (string) $status );
			$status = 'auto-draft' === $status || 'trash' === $status ? $status : 'wc-' . $status;
			// The status column holds 20 characters, so longer values are stored cut short.
			$statuses[] = mb_substr( $status, 0, 20 );
		}

		// With nothing to leave out, an empty value keeps the query valid and matches no stored status.
		return $statuses ? $statuses : array( '' );
	}
}
