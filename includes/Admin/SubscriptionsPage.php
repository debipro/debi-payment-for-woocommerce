<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Admin;

use DebiPro\Subscription\SubscriptionReconciler;

/**
 * WooCommerce submenu page listing this store's Debi subscriptions.
 *
 * Mostly read-only. Two per-row mutations:
 *   - "Reconcile" re-pulls one subscription from Debi (runs immediately, then
 *     redirect-GET so a refresh does not repeat it).
 *   - "Cancel" is destructive, so it goes through an interstitial confirmation
 *     screen; only the confirmed step calls Debi.
 * Both are gated by a per-id nonce and the manage_woocommerce capability.
 *
 * Registered on every request (the hooks only fire in wp-admin); the list table
 * class — which pulls in WP_List_Table — is loaded lazily, only when used.
 */
final class SubscriptionsPage {

	public const SLUG = 'debipro-subscriptions';

	private const CAPABILITY = 'manage_woocommerce';

	/** The admin page hook / screen id, captured at registration. */
	private static string $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	public static function register_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Debi Subscriptions', 'debi-payment-for-woocommerce' ),
			__( 'Debi Subscriptions', 'debi-payment-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);

		if ( $hook ) {
			self::$hook = $hook;
			add_action( 'load-' . $hook, array( __CLASS__, 'on_load' ) );
		}
	}

	/**
	 * On page load: register the column set for Screen Options (so every mirrored
	 * field can be toggled) and its default-hidden subset, then run row actions.
	 */
	public static function on_load(): void {
		add_filter( 'manage_' . self::$hook . '_columns', array( __CLASS__, 'register_screen_columns' ) );
		add_filter( 'default_hidden_columns', array( __CLASS__, 'filter_default_hidden_columns' ), 10, 2 );
		self::handle_actions();
	}

	/**
	 * Expose the full column set to WP's Screen Options column toggles.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function register_screen_columns( $columns ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature; the full set is returned regardless of input.
		return SubscriptionsListTable::columns();
	}

	/**
	 * Default-hide the verbose columns on this screen only (admins can re-enable
	 * them from Screen Options; the choice is stored per user).
	 *
	 * @param array<int,string> $hidden
	 * @param \WP_Screen        $screen
	 * @return array<int,string>
	 */
	public static function filter_default_hidden_columns( $hidden, $screen ): array {
		if ( is_object( $screen ) && isset( $screen->id ) && self::$hook === $screen->id ) {
			$hidden = array_merge( (array) $hidden, SubscriptionsListTable::default_hidden() );
		}
		return (array) $hidden;
	}

	/**
	 * Run row-action mutations before the page renders (so we can redirect-GET).
	 *
	 * Reconcile runs immediately. Cancel only runs once confirmed (confirmed=1);
	 * the unconfirmed cancel click falls through to {@see self::render()}, which
	 * shows the confirmation screen.
	 */
	public static function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Branches below verify a per-id nonce before any mutation.
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';
		if ( 'reconcile' !== $action && 'cancel' !== $action ) {
			return;
		}

		$id = self::requested_id();

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'debi-payment-for-woocommerce' ) );
		}

		if ( 'reconcile' === $action ) {
			check_admin_referer( 'debipro_reconcile_' . $id );
			self::redirect_with_msg( SubscriptionReconciler::reconcile_one( $id ) );
		}

		// Cancel: verify the nonce either way, but only mutate once confirmed.
		check_admin_referer( 'debipro_cancel_' . $id );
		if ( ! self::is_confirmed() ) {
			return; // render() draws the confirmation screen.
		}
		self::redirect_with_msg( SubscriptionReconciler::cancel_one( $id ) );
	}

	/**
	 * Redirect back to the clean list URL carrying only a result message, then
	 * stop. PRG so a browser refresh never repeats the action.
	 */
	private static function redirect_with_msg( string $result ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::SLUG,
					'debipro_msg' => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @return string The sanitized subscription id from the request, or ''.
	 */
	private static function requested_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The caller verifies the matching per-id nonce.
		return isset( $_GET['subscription_id'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['subscription_id'] ) ) : '';
	}

	private static function is_confirmed(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only a boolean flag; the action itself is nonce-gated.
		return isset( $_GET['confirmed'] ) && '1' === (string) wp_unslash( $_GET['confirmed'] );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'debi-payment-for-woocommerce' ) );
		}

		$pending_cancel = self::pending_cancel_id();
		if ( '' !== $pending_cancel ) {
			self::render_cancel_confirmation( $pending_cancel );
			return;
		}

		$table = new SubscriptionsListTable();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Debi Subscriptions', 'debi-payment-for-woocommerce' ); ?></h1>
			<?php self::render_notice(); ?>
			<p class="description">
				<?php esc_html_e( 'Open-ended recurring subscriptions mirrored from Debi for this store. Read-only; kept current by webhooks and "wp debipro reconcile-subscriptions".', 'debi-payment-for-woocommerce' ); ?>
			</p>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The subscription id awaiting a cancel confirmation, or '' when not in that
	 * flow. Re-verifies the nonce (handle_actions already did, defensively).
	 *
	 * @return string
	 */
	private static function pending_cancel_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified explicitly just below.
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';
		if ( 'cancel' !== $action || self::is_confirmed() ) {
			return '';
		}

		$id = self::requested_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is the verification.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( '' === $id || ! wp_verify_nonce( $nonce, 'debipro_cancel_' . $id ) ) {
			return '';
		}

		return $id;
	}

	/**
	 * Interstitial "are you sure?" screen for the destructive cancel action.
	 *
	 * @param string $id Subscription id to be cancelled.
	 */
	private static function render_cancel_confirmation( string $id ): void {
		$confirm_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'            => self::SLUG,
					'action'          => 'cancel',
					'subscription_id' => rawurlencode( $id ),
					'confirmed'       => '1',
				),
				admin_url( 'admin.php' )
			),
			'debipro_cancel_' . $id
		);
		$back_url    = menu_page_url( self::SLUG, false );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cancel subscription', 'debi-payment-for-woocommerce' ); ?></h1>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: Debi subscription id. */
						esc_html__( 'Cancel subscription %s in Debi? This stops all future charges and cannot be undone from here.', 'debi-payment-for-woocommerce' ),
						'<code>' . esc_html( $id ) . '</code>'
					);
					?>
				</p>
			</div>
			<p>
				<a href="<?php echo esc_url( $confirm_url ); ?>" class="button button-primary"><?php esc_html_e( 'Yes, cancel in Debi', 'debi-payment-for-woocommerce' ); ?></a>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button"><?php esc_html_e( 'No, go back', 'debi-payment-for-woocommerce' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Show the result of a just-completed row action, if any.
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash message; no state change.
		$msg = isset( $_GET['debipro_msg'] ) ? sanitize_key( (string) wp_unslash( $_GET['debipro_msg'] ) ) : '';
		if ( '' === $msg ) {
			return;
		}

		$success = array(
			'reconciled' => __( 'Subscription reconciled from Debi.', 'debi-payment-for-woocommerce' ),
			'cancelled'  => __( 'Subscription cancelled in Debi.', 'debi-payment-for-woocommerce' ),
		);
		$errors  = array(
			'missing_id'    => __( 'No subscription was specified.', 'debi-payment-for-woocommerce' ),
			'client_error'  => __( 'Debi is not configured on this store (check the gateway API keys).', 'debi-payment-for-woocommerce' ),
			'fetch_failed'  => __( 'Could not fetch the subscription from Debi. Please try again.', 'debi-payment-for-woocommerce' ),
			'cancel_failed' => __( 'Could not cancel the subscription in Debi. Please try again.', 'debi-payment-for-woocommerce' ),
		);

		if ( isset( $success[ $msg ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $success[ $msg ] )
			);
			return;
		}

		if ( isset( $errors[ $msg ] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $errors[ $msg ] )
			);
		}
	}
}
