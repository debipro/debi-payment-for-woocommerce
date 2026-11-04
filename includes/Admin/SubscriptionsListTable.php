<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Admin;

use DebiPro\Subscription\SubscriptionsTable;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Read-only admin list of the current store's Debi subscriptions mirror.
 *
 * Reads the per-site {@see SubscriptionsTable} directly; it never calls Debi.
 * The table is kept current by the plugin's webhooks and the
 * `wp debipro reconcile-subscriptions` CLI safety net.
 */
final class SubscriptionsListTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'debipro_subscription',
				'plural'   => 'debipro_subscriptions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The full column set, mirroring the stored Debi subscription fields. Static so
	 * {@see SubscriptionsPage} can register it for the Screen Options column
	 * toggles without constructing the table.
	 *
	 * @return array<string,string>
	 */
	public static function columns(): array {
		return array(
			'subscription_id' => __( 'Subscription', 'debi-payment-for-woocommerce' ),
			'customer'        => __( 'Customer', 'debi-payment-for-woocommerce' ),
			'product'         => __( 'Product', 'debi-payment-for-woocommerce' ),
			'link_id'         => __( 'Link', 'debi-payment-for-woocommerce' ),
			'amount'          => __( 'Amount', 'debi-payment-for-woocommerce' ),
			'status'          => __( 'Status', 'debi-payment-for-woocommerce' ),
			'schedule'        => __( 'Schedule', 'debi-payment-for-woocommerce' ),
			'total_count'     => __( 'Payments', 'debi-payment-for-woocommerce' ),
			'payment_method'  => __( 'Payment method', 'debi-payment-for-woocommerce' ),
			'next_charge'     => __( 'Next charge', 'debi-payment-for-woocommerce' ),
			'livemode'        => __( 'Mode', 'debi-payment-for-woocommerce' ),
			'start_date'      => __( 'Start', 'debi-payment-for-woocommerce' ),
			'debi_created_at' => __( 'Debi created', 'debi-payment-for-woocommerce' ),
			'debi_updated_at' => __( 'Debi updated', 'debi-payment-for-woocommerce' ),
			'created_at'      => __( 'Mirrored', 'debi-payment-for-woocommerce' ),
			'updated_at'      => __( 'Synced', 'debi-payment-for-woocommerce' ),
		);
	}

	/**
	 * Columns hidden by default so the table is readable out of the box; the admin
	 * can reveal any of them from Screen Options.
	 *
	 * @return array<int,string>
	 */
	public static function default_hidden(): array {
		return array( 'link_id', 'start_date', 'debi_created_at', 'debi_updated_at', 'created_at' );
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return self::columns();
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'amount'          => array( 'amount', false ),
			'status'          => array( 'status', false ),
			'livemode'        => array( 'livemode', false ),
			'start_date'      => array( 'start_date', false ),
			'debi_created_at' => array( 'debi_created_at', false ),
			'created_at'      => array( 'created_at', false ),
			'updated_at'      => array( 'updated_at', true ),
		);
	}

	/**
	 * Status filter links above the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		$counts  = SubscriptionsTable::status_counts();
		$total   = array_sum( $counts );
		$current = $this->current_status();
		$base    = menu_page_url( SubscriptionsPage::SLUG, false );

		$views        = array();
		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $base ),
			'' === $current ? ' class="current"' : '',
			esc_html__( 'All', 'debi-payment-for-woocommerce' ),
			(int) $total
		);

		foreach ( $counts as $status => $count ) {
			if ( '' === $status ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', rawurlencode( $status ), $base ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( ucfirst( $status ) ),
				(int) $count
			);
		}

		return $views;
	}

	public function no_items(): void {
		esc_html_e( 'No Debi subscriptions on this store yet.', 'debi-payment-for-woocommerce' );
	}

	public function prepare_items(): void {
		$hidden = function_exists( 'get_hidden_columns' ) && $this->screen
			? get_hidden_columns( $this->screen )
			: array();

		$this->_column_headers = array(
			$this->get_columns(),
			$hidden,
			$this->get_sortable_columns(),
			'subscription_id',
		);

		$status   = $this->current_status();
		$per_page = self::PER_PAGE;
		$offset   = ( $this->get_pagenum() - 1 ) * $per_page;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list; GET sort/filter needs no nonce.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['orderby'] ) ) : 'updated_at';
		$order   = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( (string) wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->items = SubscriptionsTable::paged_rows( $per_page, $offset, $status, $orderby, $order );

		$total = SubscriptionsTable::count_rows( $status );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Primary column: the Debi id, with a "Reconcile" row action that re-pulls
	 * this one subscription from Debi.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_subscription_id( array $item ): string {
		$id   = (string) ( $item['subscription_id'] ?? '' );
		$cell = '<code>' . esc_html( $id ) . '</code>';

		if ( '' === $id ) {
			return $cell;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'page'            => SubscriptionsPage::SLUG,
					'action'          => 'reconcile',
					'subscription_id' => rawurlencode( $id ),
				),
				admin_url( 'admin.php' )
			),
			'debipro_reconcile_' . $id
		);

		$actions = array(
			'reconcile' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'Reconcile', 'debi-payment-for-woocommerce' )
			),
		);

		// Only offer "Cancel" on subscriptions that can still be cancelled; a
		// finished/cancelled/incomplete one has nothing to stop.
		$status = (string) ( $item['status'] ?? '' );
		if ( in_array( $status, array( 'active', 'paused' ), true ) ) {
			$cancel_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'            => SubscriptionsPage::SLUG,
						'action'          => 'cancel',
						'subscription_id' => rawurlencode( $id ),
					),
					admin_url( 'admin.php' )
				),
				'debipro_cancel_' . $id
			);

			$actions['cancel'] = sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $cancel_url ),
				esc_html__( 'Cancel', 'debi-payment-for-woocommerce' )
			);
		}

		return $cell . $this->row_actions( $actions );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_customer( array $item ): string {
		$uid = (int) ( $item['wp_user_id'] ?? 0 );
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( $user ) {
				$name = '' !== $user->display_name ? $user->display_name : $user->user_email;
				$edit = get_edit_user_link( $uid );
				$line = $edit
					? '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>'
					: esc_html( $name );
				return $line . '<br><span class="description">' . esc_html( $user->user_email ) . '</span>';
			}
		}

		// No linked WP user: fall back to the Debi customer name / email, then id.
		$name  = (string) ( $item['customer_name'] ?? '' );
		$email = (string) ( $item['customer_email'] ?? '' );
		if ( '' !== $name || '' !== $email ) {
			if ( '' !== $name && '' !== $email ) {
				return esc_html( $name ) . '<br><span class="description">' . esc_html( $email ) . '</span>';
			}
			return esc_html( '' !== $name ? $name : $email );
		}

		$customer = (string) ( $item['customer_id'] ?? '' );
		if ( '' !== $customer ) {
			return '<span class="description">' . esc_html( $customer ) . '</span>';
		}

		return esc_html__( 'Guest', 'debi-payment-for-woocommerce' );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_product( array $item ): string {
		$pid = (int) ( $item['product_id'] ?? 0 );
		if ( $pid <= 0 ) {
			return '&mdash;';
		}
		$title = get_the_title( $pid );
		$title = '' !== $title ? $title : sprintf( '#%d', $pid );
		$edit  = get_edit_post_link( $pid );
		return $edit
			? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>'
			: esc_html( $title );
	}

	/**
	 * The originating hosted link id, when the subscription was born from one.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_link_id( array $item ): string {
		$link_id = (string) ( $item['link_id'] ?? '' );
		return '' !== $link_id ? '<code>' . esc_html( $link_id ) . '</code>' : '&mdash;';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_amount( array $item ): string {
		$amount = (float) ( $item['amount'] ?? 0 );
		$out    = function_exists( 'wc_price' )
			? wp_kses_post( wc_price( $amount ) )
			: esc_html( number_format_i18n( $amount, 2 ) );

		$currency = strtoupper( (string) ( $item['currency'] ?? '' ) );
		if ( '' !== $currency ) {
			$out .= ' <span class="description">' . esc_html( $currency ) . '</span>';
		}
		return $out;
	}

	/**
	 * Billing cadence: interval + the day it bills on.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_schedule( array $item ): string {
		$unit = (string) ( $item['interval_unit'] ?? '' );
		if ( '' === $unit ) {
			return '&mdash;';
		}
		$every = (int) ( $item['interval_count'] ?? 0 );
		$label = $every > 1
			? sprintf(
				/* translators: 1: interval count, 2: interval unit (weekly/monthly/yearly). */
				esc_html__( 'Every %1$d %2$s', 'debi-payment-for-woocommerce' ),
				$every,
				esc_html( $unit )
			)
			: esc_html( ucfirst( $unit ) );

		$dow = $item['day_of_week'] ?? null;
		$dom = (int) ( $item['day_of_month'] ?? 0 );
		if ( 'weekly' === $unit && null !== $dow && '' !== $dow ) {
			$label .= ' · ' . sprintf(
				/* translators: %d: day of week (0-6). */
				esc_html__( 'weekday %d', 'debi-payment-for-woocommerce' ),
				(int) $dow
			);
		} elseif ( $dom > 0 ) {
			$label .= ' · ' . sprintf(
				/* translators: %d: day of month. */
				esc_html__( 'day %d', 'debi-payment-for-woocommerce' ),
				$dom
			);
		}
		return $label;
	}

	/**
	 * Total scheduled payments, or "open-ended" when the subscription has no count.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_total_count( array $item ): string {
		$count = $item['total_count'] ?? null;
		if ( null === $count || '' === $count ) {
			return esc_html__( 'Open-ended', 'debi-payment-for-woocommerce' );
		}
		return esc_html( (string) (int) $count );
	}

	/**
	 * Card brand + last four, or the payment-method type for non-card instruments.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_payment_method( array $item ): string {
		$brand = (string) ( $item['card_brand'] ?? '' );
		$last4 = (string) ( $item['card_last4'] ?? '' );
		$type  = (string) ( $item['payment_method_type'] ?? '' );
		$pm_id = (string) ( $item['payment_method_id'] ?? '' );

		// Card: show the network and the last four digits ("Visa ending in 4242");
		// anything else falls back to the humanized method type.
		if ( '' !== $last4 ) {
			$network = '' !== $brand ? ucfirst( $brand ) : __( 'Card', 'debi-payment-for-woocommerce' );
			/* translators: 1: card network (e.g. Visa), 2: last four digits. */
			$label = sprintf( __( '%1$s ending in %2$s', 'debi-payment-for-woocommerce' ), $network, $last4 );
		} elseif ( '' !== $type ) {
			$label = ucfirst( str_replace( '_', ' ', $type ) );
		} else {
			$label = '';
		}

		if ( '' === $label ) {
			return '&mdash;';
		}

		// Append the payment method id so it can be cross-referenced in Debi.
		if ( '' !== $pm_id ) {
			$label .= ' (' . $pm_id . ')';
		}

		return esc_html( $label );
	}

	/**
	 * The soonest upcoming charge date, flagging how many more are scheduled.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_next_charge( array $item ): string {
		$raw = (string) ( $item['upcoming_dates'] ?? '' );
		if ( '' === $raw ) {
			return '&mdash;';
		}
		$dates = json_decode( $raw, true );
		if ( ! is_array( $dates ) || array() === $dates ) {
			return '&mdash;';
		}
		$out  = self::format_date( (string) $dates[0] );
		$more = count( $dates ) - 1;
		if ( $more > 0 ) {
			$out .= ' <span class="description">' . sprintf(
				/* translators: %d: number of further scheduled charges. */
				esc_html__( '+%d more', 'debi-payment-for-woocommerce' ),
				$more
			) . '</span>';
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_livemode( array $item ): string {
		return ! empty( $item['livemode'] )
			? esc_html__( 'Live', 'debi-payment-for-woocommerce' )
			: '<span class="description">' . esc_html__( 'Test', 'debi-payment-for-woocommerce' ) . '</span>';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_start_date( array $item ): string {
		return self::format_dt( $item['start_date'] ?? null );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_debi_created_at( array $item ): string {
		return self::format_dt( $item['debi_created_at'] ?? null );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_debi_updated_at( array $item ): string {
		return self::format_dt( $item['debi_updated_at'] ?? null );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_status( array $item ): string {
		$status = (string) ( $item['status'] ?? '' );
		return '' !== $status ? esc_html( ucfirst( $status ) ) : '&mdash;';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_created_at( array $item ): string {
		return self::format_dt( $item['created_at'] ?? null );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_updated_at( array $item ): string {
		return self::format_dt( $item['updated_at'] ?? null );
	}

	/**
	 * Fallback renderer for any column without a dedicated method.
	 *
	 * @param array<string,mixed> $item
	 * @param string              $column_name
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		$value = $item[ $column_name ] ?? '';
		return esc_html( is_scalar( $value ) ? (string) $value : '' );
	}

	private function current_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET filter.
		return isset( $_REQUEST['status'] ) ? sanitize_text_field( (string) wp_unslash( $_REQUEST['status'] ) ) : '';
	}

	/**
	 * Render a stored GMT datetime in the site's timezone, or an em dash.
	 *
	 * @param mixed $value Stored datetime string.
	 * @return string
	 */
	private static function format_dt( $value ): string {
		$value = (string) $value;
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '&mdash;';
		}
		$ts = strtotime( $value . ' UTC' );
		if ( ! $ts ) {
			return '&mdash;';
		}
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) );
	}

	/**
	 * Render a loosely-typed date string (e.g. a Debi upcoming-charge date, which
	 * may be date-only or carry its own timezone) as a date, falling back to the
	 * raw value when it cannot be parsed.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function format_date( $value ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '&mdash;';
		}
		$ts = strtotime( $value );
		return false === $ts
			? esc_html( $value )
			: esc_html( wp_date( get_option( 'date_format' ), $ts ) );
	}
}
