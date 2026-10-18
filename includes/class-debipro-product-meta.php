<?php
/**
 * Per-product Debi configuration meta fields.
 *
 * Adds a "Debi" tab to the WooCommerce product edit page with:
 *   - default_session_type (payment | installment | subscription | mandate)
 *   - checkout_mode        (inherit | embedded | redirect)
 *   - amount_mode          (fixed | editable)
 *   - monthly_interest     percentage
 *   - installments         fixed count — mutually exclusive with max_installments
 *   - max_installments     customer chooses 1..N — mutually exclusive with installments
 *   - surcharge            percentage
 *
 * Empty fields inherit their value from the plugin's global defaults.
 *
 * @package WooCommerce_Debi
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/debipro-session-type.php';

class DEBIPRO_Product_Meta {

	const SESSION_TYPE_KEY  = '_debipro_default_session_type';
	const CHECKOUT_MODE_KEY = '_debipro_checkout_mode';
	const AMOUNT_MODE_KEY   = '_debipro_amount_mode';
	const INTEREST_KEY      = '_debipro_monthly_interest_percentage';
	const INSTALL_KEY       = '_debipro_installments';
	const MAX_INST_KEY      = '_debipro_max_installments';
	const SURCHARGE_KEY     = '_debipro_surcharge_percentage';

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_meta' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	public static function add_product_tab( $tabs ) {
		$tabs['debipro'] = array(
			'label'  => __( 'Debi', 'debi-payment-for-woocommerce' ),
			'target' => 'debipro_product_data',
			'class'  => array(),
		);
		return $tabs;
	}

	/**
	 * The selectable default session types, as id => label.
	 *
	 * @return array<string, string>
	 */
	public static function session_type_options(): array {
		return array(
			'payment'      => __( 'Payment', 'debi-payment-for-woocommerce' ),
			'installment'  => __( 'Installment', 'debi-payment-for-woocommerce' ),
			'subscription' => __( 'Subscription', 'debi-payment-for-woocommerce' ),
			'mandate'      => __( 'Mandate', 'debi-payment-for-woocommerce' ),
		);
	}

	private static function normalize_session_type( string $type ): string {
		$resolved = DebiProSessionType::tryFrom( $type );

		return null !== $resolved ? $resolved->value : DebiProSessionType::Installment->value;
	}

	private static function normalize_checkout_mode( string $mode ): string {
		return in_array( $mode, array( 'embedded', 'redirect' ), true ) ? $mode : 'embedded';
	}

	private static function normalize_amount_mode( string $mode ): string {
		return in_array( $mode, array( 'fixed', 'editable' ), true ) ? $mode : 'fixed';
	}

	/**
	 * Read a plugin-level default, from the gateway when available or the raw
	 * WC options row otherwise.
	 *
	 * @param string                  $key
	 * @param mixed                   $fallback
	 * @param WC_Payment_Gateway|null $gateway
	 * @return mixed
	 */
	private static function read_setting( string $key, $fallback, $gateway = null ) {
		if ( $gateway ) {
			return $gateway->get_option( $key, $fallback );
		}
		$s = get_option( 'woocommerce_debipro_settings', array() );
		return isset( $s[ $key ] ) ? $s[ $key ] : $fallback;
	}

	private static function get_defaults() {
		$s = get_option( 'woocommerce_debipro_settings', array() );
		return array(
			'type'          => self::normalize_session_type( isset( $s['default_session_type'] ) ? (string) $s['default_session_type'] : 'installment' ),
			'checkout_mode' => self::normalize_checkout_mode( isset( $s['default_checkout_mode'] ) ? (string) $s['default_checkout_mode'] : 'embedded' ),
			'interest'      => isset( $s['default_monthly_interest_percentage'] ) ? $s['default_monthly_interest_percentage'] : '2',
			'install'       => isset( $s['default_installments'] ) ? $s['default_installments'] : '',
			'max'           => isset( $s['default_max_installments'] ) ? $s['default_max_installments'] : '',
			'surcharge'     => isset( $s['default_surcharge_percentage'] ) ? $s['default_surcharge_percentage'] : '0',
		);
	}

	/**
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen filter, no state change.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( '' === $post_type && isset( $_GET['post'] ) ) {
			$post_type = get_post_type( (int) $_GET['post'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'product' !== $post_type ) {
			return;
		}

		wp_enqueue_script(
			'debipro-admin-product-meta',
			DEBIPRO_PLUGIN_URL . 'assets/js/admin-product-meta.js',
			array( 'jquery' ),
			DEBIPRO_PLUGIN_VERSION,
			true
		);

		$defaults = self::get_defaults();
		wp_localize_script(
			'debipro-admin-product-meta',
			'debiproProductMeta',
			array(
				'typeKey'            => '#' . self::SESSION_TYPE_KEY,
				'interestId'         => '#' . self::INTEREST_KEY,
				'installId'          => '#' . self::INSTALL_KEY,
				'maxInstId'          => '#' . self::MAX_INST_KEY,
				'installPlaceholder' => $defaults['install'],
				'maxPlaceholder'     => $defaults['max'],
			)
		);
	}

	public static function render_product_panel() {
		global $post;
		$pid = (int) $post->ID;
		$d   = self::get_defaults();

		$type          = get_post_meta( $pid, self::SESSION_TYPE_KEY, true );
		$checkout_mode = get_post_meta( $pid, self::CHECKOUT_MODE_KEY, true );
		$amount_mode   = get_post_meta( $pid, self::AMOUNT_MODE_KEY, true );
		$interest      = get_post_meta( $pid, self::INTEREST_KEY, true );
		$install       = get_post_meta( $pid, self::INSTALL_KEY, true );
		$max           = get_post_meta( $pid, self::MAX_INST_KEY, true );
		$surch         = get_post_meta( $pid, self::SURCHARGE_KEY, true );
		?>
		<div id="debipro_product_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_select(
					array(
						'id'          => self::SESSION_TYPE_KEY,
						'label'       => __( 'Default checkout type', 'debi-payment-for-woocommerce' ),
						'value'       => $type ? $type : $d['type'],
						'desc_tip'    => true,
						'description' => __( 'The Debi session this product opens by default. Payment: a single charge (optionally split into installments). Subscription: a recurring charge. Mandate: capture a payment method for later charges.', 'debi-payment-for-woocommerce' ),
						'options'     => self::session_type_options(),
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => self::CHECKOUT_MODE_KEY,
						'label'       => __( 'Checkout mode', 'debi-payment-for-woocommerce' ),
						'value'       => $checkout_mode,
						'desc_tip'    => true,
						'description' => __( 'Embedded: the card is captured on this site. Redirect: the customer is sent to Debi\'s hosted checkout. Empty = use the plugin default.', 'debi-payment-for-woocommerce' ),
						'options'     => array(
							''         => sprintf(
								/* translators: %s: the plugin-level default checkout mode. */
								__( 'Use default (%s)', 'debi-payment-for-woocommerce' ),
								ucfirst( $d['checkout_mode'] )
							),
							'embedded' => __( 'Embedded', 'debi-payment-for-woocommerce' ),
							'redirect' => __( 'Redirect', 'debi-payment-for-woocommerce' ),
						),
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => self::AMOUNT_MODE_KEY,
						'label'       => __( 'Amount', 'debi-payment-for-woocommerce' ),
						'value'       => $amount_mode ? $amount_mode : 'fixed',
						'desc_tip'    => true,
						'description' => __( 'Fixed: the product price is charged. Customer-defined: the buyer sets the amount (useful for donations and free-amount subscriptions).', 'debi-payment-for-woocommerce' ),
						'options'     => array(
							'fixed'    => __( 'Fixed amount', 'debi-payment-for-woocommerce' ),
							'editable' => __( 'Customer-defined amount', 'debi-payment-for-woocommerce' ),
						),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => self::INTEREST_KEY,
						'label'             => __( 'Monthly interest (%)', 'debi-payment-for-woocommerce' ),
						'placeholder'       => '' !== $d['interest'] ? $d['interest'] : '0',
						'value'             => $interest,
						'desc_tip'          => true,
						'description'       => __( 'Empty = use the plugin\'s default value.', 'debi-payment-for-woocommerce' ),
						'type'              => 'number',
						'custom_attributes' => array(
							'min'  => 0,
							'step' => '0.01',
						),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => self::INSTALL_KEY,
						'label'             => __( 'Fixed installments', 'debi-payment-for-woocommerce' ),
						'placeholder'       => ( '' !== $max && is_numeric( $max ) ) ? '' : $d['install'],
						'value'             => $install,
						'desc_tip'          => true,
						'description'       => __( 'Fixed number of installments. Mutually exclusive with "Maximum installments".', 'debi-payment-for-woocommerce' ),
						'type'              => 'number',
						'custom_attributes' => array(
							'min'  => 1,
							'step' => 1,
						),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => self::MAX_INST_KEY,
						'label'             => __( 'Maximum installments', 'debi-payment-for-woocommerce' ),
						'placeholder'       => ( '' !== $install && is_numeric( $install ) ) ? '' : $d['max'],
						'value'             => $max,
						'desc_tip'          => true,
						'description'       => __( 'The customer freely chooses between 1 and this value. Mutually exclusive with "Fixed installments".', 'debi-payment-for-woocommerce' ),
						'type'              => 'number',
						'custom_attributes' => array(
							'min'  => 1,
							'step' => 1,
						),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => self::SURCHARGE_KEY,
						'label'             => __( 'Surcharge (%)', 'debi-payment-for-woocommerce' ),
						'placeholder'       => '' !== $d['surcharge'] ? $d['surcharge'] : '0',
						'value'             => $surch,
						'desc_tip'          => true,
						'description'       => __( 'Empty = use the plugin\'s default value.', 'debi-payment-for-woocommerce' ),
						'type'              => 'number',
						'custom_attributes' => array(
							'min'  => 0,
							'step' => '0.01',
						),
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	public static function save_product_meta( $post_id ) {
		// WooCommerce verifies woocommerce_meta_nonce before this hook runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing

		// Default session type
		$type = isset( $_POST[ self::SESSION_TYPE_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::SESSION_TYPE_KEY ] ) ) : '';
		if ( DebiProSessionType::tryFrom( $type ) !== null ) {
			update_post_meta( $post_id, self::SESSION_TYPE_KEY, $type );
		} else {
			delete_post_meta( $post_id, self::SESSION_TYPE_KEY );
		}

		// Checkout mode: empty value means "inherit the plugin default".
		$checkout_mode = isset( $_POST[ self::CHECKOUT_MODE_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CHECKOUT_MODE_KEY ] ) ) : '';
		if ( in_array( $checkout_mode, array( 'embedded', 'redirect' ), true ) ) {
			update_post_meta( $post_id, self::CHECKOUT_MODE_KEY, $checkout_mode );
		} else {
			delete_post_meta( $post_id, self::CHECKOUT_MODE_KEY );
		}

		// Amount mode
		$amount_mode = isset( $_POST[ self::AMOUNT_MODE_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::AMOUNT_MODE_KEY ] ) ) : '';
		if ( in_array( $amount_mode, array( 'fixed', 'editable' ), true ) ) {
			update_post_meta( $post_id, self::AMOUNT_MODE_KEY, $amount_mode );
		} else {
			delete_post_meta( $post_id, self::AMOUNT_MODE_KEY );
		}

		// installments / max_installments are mutually exclusive; whichever is
		// non-empty wins and clears the other.
		$install_raw = isset( $_POST[ self::INSTALL_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::INSTALL_KEY ] ) ) : '';
		$max_raw     = isset( $_POST[ self::MAX_INST_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::MAX_INST_KEY ] ) ) : '';

		$install_ok = is_numeric( $install_raw ) && (int) $install_raw >= 1;
		$max_ok     = is_numeric( $max_raw ) && (int) $max_raw >= 1;

		if ( $install_ok ) {
			update_post_meta( $post_id, self::INSTALL_KEY, (int) $install_raw );
			delete_post_meta( $post_id, self::MAX_INST_KEY );
		} elseif ( $max_ok ) {
			update_post_meta( $post_id, self::MAX_INST_KEY, (int) $max_raw );
			delete_post_meta( $post_id, self::INSTALL_KEY );
		} else {
			delete_post_meta( $post_id, self::INSTALL_KEY );
			delete_post_meta( $post_id, self::MAX_INST_KEY );
		}

		// Float fields
		foreach ( array( self::INTEREST_KEY, self::SURCHARGE_KEY ) as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			if ( is_numeric( $value ) ) {
				update_post_meta( $post_id, $field, (float) $value );
			} else {
				delete_post_meta( $post_id, $field );
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Resolve the product's checkout mode, inheriting the plugin default when
	 * the product does not declare one.
	 *
	 * @param int                     $product_id
	 * @param WC_Payment_Gateway|null $gateway
	 * @return string 'embedded'|'redirect'
	 */
	public static function get_checkout_mode( $product_id, $gateway = null ): string {
		$mode = (string) get_post_meta( $product_id, self::CHECKOUT_MODE_KEY, true );
		if ( in_array( $mode, array( 'embedded', 'redirect' ), true ) ) {
			return $mode;
		}
		return self::normalize_checkout_mode( (string) self::read_setting( 'default_checkout_mode', 'embedded', $gateway ) );
	}

	/**
	 * Resolve the product's amount mode.
	 *
	 * @param int $product_id
	 * @return string 'fixed'|'editable'
	 */
	public static function get_amount_mode( $product_id ): string {
		return self::normalize_amount_mode( (string) get_post_meta( $product_id, self::AMOUNT_MODE_KEY, true ) );
	}

	/**
	 * Returns the resolved financing configuration for a product, applying global
	 * defaults for any field the product leaves empty.
	 *
	 * @param int                         $product_id
	 * @param WC_Payment_Gateway|null     $gateway    Pass the gateway instance for
	 *                                                get_option() fallback; null reads
	 *                                                directly from the WC options row.
	 * @return array{type:DebiProSessionType,monthly_interest:float,installments:int|null,max_installments:int|null,surcharge:float}
	 */
	public static function get_product_financing( $product_id, $gateway = null ) {
		$type             = get_post_meta( $product_id, self::SESSION_TYPE_KEY, true );
		$interest         = get_post_meta( $product_id, self::INTEREST_KEY, true );
		$install          = get_post_meta( $product_id, self::INSTALL_KEY, true );
		$max              = get_post_meta( $product_id, self::MAX_INST_KEY, true );
		$surch            = get_post_meta( $product_id, self::SURCHARGE_KEY, true );
		$has_install_meta = metadata_exists( 'post', $product_id, self::INSTALL_KEY );
		$has_max_meta     = metadata_exists( 'post', $product_id, self::MAX_INST_KEY );

		$opt = function ( $key, $fallback ) use ( $gateway ) {
			return self::read_setting( $key, $fallback, $gateway );
		};

		if ( DebiProSessionType::tryFrom( (string) $type ) === null ) {
			$type = self::normalize_session_type( (string) $opt( 'default_session_type', DebiProSessionType::Installment->value ) );
		}
		if ( ! is_numeric( $interest ) ) {
			$interest = $opt( 'default_monthly_interest_percentage', 2 );
		}
		if ( ( ! is_numeric( $install ) || (int) $install < 1 ) && ! $has_max_meta ) {
			$install = $opt( 'default_installments', '' );
		}
		if ( ( ! is_numeric( $max ) || (int) $max < 1 ) && ! $has_install_meta ) {
			$max = $opt( 'default_max_installments', '' );
		}
		if ( ! is_numeric( $surch ) ) {
			$surch = $opt( 'default_surcharge_percentage', 0 );
		}

		// installments wins over max_installments when both somehow resolve.
		if ( is_numeric( $install ) && (int) $install >= 1 ) {
			$install_count = (int) $install;
			$max_count     = null;
		} elseif ( is_numeric( $max ) && (int) $max >= 1 ) {
			$install_count = null;
			$max_count     = (int) $max;
		} else {
			$install_count = null;
			$max_count     = null;
		}

		return array(
			'type'             => DebiProSessionType::from( self::normalize_session_type( (string) $type ) ),
			'monthly_interest' => (float) $interest,
			'installments'     => $install_count,
			'max_installments' => $max_count,
			'surcharge'        => (float) $surch,
		);
	}
}
