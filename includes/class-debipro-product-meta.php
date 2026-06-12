<?php
/**
 * Per-product Debi configuration meta fields.
 *
 * Adds a "Debi" tab to the WooCommerce product edit page with five fields:
 *   - type             (subscription | payment)
 *   - monthly_interest percentage
 *   - installments     fixed count — mutually exclusive with max_installments
 *   - max_installments customer chooses 1..N — mutually exclusive with installments
 *   - surcharge        percentage
 *
 * Empty fields inherit their value from the plugin's global defaults.
 *
 * @package WooCommerce_Debi
 */

if (!defined('WPINC')) {
	die;
}

enum DebiProFinancingType: string {
	case Subscription   = 'subscription';
	case Installment    = 'installment';
	case OneTimePayment = 'one_time';
}

class DEBIPRO_Product_Meta {

	const TYPE_KEY      = '_debipro_type';
	const INTEREST_KEY  = '_debipro_monthly_interest_percentage';
	const INSTALL_KEY   = '_debipro_installments';
	const MAX_INST_KEY  = '_debipro_max_installments';
	const SURCHARGE_KEY = '_debipro_surcharge_percentage';

	public static function init() {
		add_filter('woocommerce_product_data_tabs', [__CLASS__, 'add_product_tab']);
		add_action('woocommerce_product_data_panels', [__CLASS__, 'render_product_panel']);
		add_action('woocommerce_process_product_meta', [__CLASS__, 'save_product_meta']);
	}

	public static function add_product_tab($tabs) {
		$tabs['debipro'] = [
			'label'  => __('Debi', 'debi-payment-for-woocommerce'),
			'target' => 'debipro_product_data',
			'class'  => [],
		];
		return $tabs;
	}

	private static function get_defaults() {
		$s = get_option('woocommerce_debipro_settings', []);
		return [
			'type'     => isset($s['default_type']) ? $s['default_type'] : 'subscription',
			'interest' => isset($s['default_monthly_interest_percentage']) ? $s['default_monthly_interest_percentage'] : '2',
			'install'  => isset($s['default_installments']) ? $s['default_installments'] : '',
			'max'      => isset($s['default_max_installments']) ? $s['default_max_installments'] : '',
			'surcharge'=> isset($s['default_surcharge_percentage']) ? $s['default_surcharge_percentage'] : '0',
		];
	}

	public static function render_product_panel() {
		global $post;
		$pid = (int) $post->ID;
		$d   = self::get_defaults();

		$type    = get_post_meta($pid, self::TYPE_KEY, true);
		$interest= get_post_meta($pid, self::INTEREST_KEY, true);
		$install = get_post_meta($pid, self::INSTALL_KEY, true);
		$max     = get_post_meta($pid, self::MAX_INST_KEY, true);
		$surch   = get_post_meta($pid, self::SURCHARGE_KEY, true);
		?>
		<div id="debipro_product_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_select([
                    'id'          => self::TYPE_KEY,
                    'label'       => __('Type', 'debi-payment-for-woocommerce'),
                    'value'       => $type ?: $d['type'],
                    'desc_tip'    => true,
                    'description' => __('subscription: recurring payments with no fixed end. payment: single payment, no additional configuration per product.', 'debi-payment-for-woocommerce'),
                    'options'     => [
                        'subscription' => __('Subscription', 'debi-payment-for-woocommerce'),
                        'payment'      => __('Single payment', 'debi-payment-for-woocommerce'),
                    ],
                ]);

                woocommerce_wp_text_input([
                    'id'                => self::INTEREST_KEY,
                    'label'             => __('Monthly interest (%)', 'debi-payment-for-woocommerce'),
                    'placeholder'       => $d['interest'] !== '' ? $d['interest'] : '0',
                    'value'             => $interest,
                    'desc_tip'          => true,
                    'description'       => __('Empty = use the plugin\'s default value.', 'debi-payment-for-woocommerce'),
                    'type'              => 'number',
                    'custom_attributes' => ['min' => 0, 'step' => '0.01'],
                ]);

                woocommerce_wp_text_input([
                    'id'                => self::INSTALL_KEY,
                    'label'             => __('Fixed installments', 'debi-payment-for-woocommerce'),
                    'placeholder'       => $d['install'],
                    'value'             => $install,
                    'desc_tip'          => true,
                    'description'       => __('Fixed number of installments. Saving will clear the "Maximum installments" field.', 'debi-payment-for-woocommerce'),
                    'type'              => 'number',
                    'custom_attributes' => ['min' => 1, 'step' => 1],
                ]);

                woocommerce_wp_text_input([
                    'id'                => self::MAX_INST_KEY,
                    'label'             => __('Maximum installments', 'debi-payment-for-woocommerce'),
                    'placeholder'       => $d['max'],
                    'value'             => $max,
                    'desc_tip'          => true,
                    'description'       => __('The customer freely chooses between 1 and this value. Saving will clear the "Fixed installments" field.', 'debi-payment-for-woocommerce'),
                    'type'              => 'number',
                    'custom_attributes' => ['min' => 1, 'step' => 1],
                ]);

                woocommerce_wp_text_input([
                    'id'                => self::SURCHARGE_KEY,
                    'label'             => __('Surcharge (%)', 'debi-payment-for-woocommerce'),
                    'placeholder'       => $d['surcharge'] !== '' ? $d['surcharge'] : '0',
                    'value'             => $surch,
                    'desc_tip'          => true,
                    'description'       => __('Empty = use the plugin\'s default value.', 'debi-payment-for-woocommerce'),
                    'type'              => 'number',
                    'custom_attributes' => ['min' => 0, 'step' => '0.01'],
                ]);
				?>
			</div>
		</div>
		<script>
		jQuery(function($) {
			var $type         = $('#<?php echo esc_js(self::TYPE_KEY); ?>');
			var $hideFields   = $(
				'#<?php echo esc_js(self::INTEREST_KEY); ?>_field,' +
				'#<?php echo esc_js(self::SURCHARGE_KEY); ?>_field'
			);
			var $installInput = $('#<?php echo esc_js(self::INSTALL_KEY); ?>');
			var $maxInput     = $('#<?php echo esc_js(self::MAX_INST_KEY); ?>');

			function toggle() {
				var isPayment = $type.val() === 'payment';
				$hideFields.toggle(!isPayment);
				$installInput.prop('disabled', isPayment);
				$maxInput.prop('disabled', isPayment);
			}

			$type.on('change', toggle);
			toggle();
		});
		</script>
		<?php
	}

	public static function save_product_meta($post_id) {
		// Type
		$type = isset($_POST[self::TYPE_KEY]) ? sanitize_text_field(wp_unslash($_POST[self::TYPE_KEY])) : '';
		if (DebiProFinancingType::tryFrom($type) !== null) {
			update_post_meta($post_id, self::TYPE_KEY, $type);
		} else {
			delete_post_meta($post_id, self::TYPE_KEY);
		}

		// installments / max_installments are mutually exclusive; whichever is
		// non-empty wins and clears the other.
		$install_raw = isset($_POST[self::INSTALL_KEY]) ? sanitize_text_field(wp_unslash($_POST[self::INSTALL_KEY])) : '';
		$max_raw     = isset($_POST[self::MAX_INST_KEY]) ? sanitize_text_field(wp_unslash($_POST[self::MAX_INST_KEY])) : '';

		$install_ok = is_numeric($install_raw) && (int) $install_raw >= 1;
		$max_ok     = is_numeric($max_raw) && (int) $max_raw >= 1;

		if ($install_ok) {
			update_post_meta($post_id, self::INSTALL_KEY, (int) $install_raw);
			delete_post_meta($post_id, self::MAX_INST_KEY);
		} elseif ($max_ok) {
			update_post_meta($post_id, self::MAX_INST_KEY, (int) $max_raw);
			delete_post_meta($post_id, self::INSTALL_KEY);
		} else {
			delete_post_meta($post_id, self::INSTALL_KEY);
			delete_post_meta($post_id, self::MAX_INST_KEY);
		}

		// Float fields
		foreach ([self::INTEREST_KEY, self::SURCHARGE_KEY] as $field) {
			if (!isset($_POST[$field])) {
				continue;
			}
			$value = sanitize_text_field(wp_unslash($_POST[$field]));
			if (is_numeric($value)) {
				update_post_meta($post_id, $field, (float) $value);
			} else {
				delete_post_meta($post_id, $field);
			}
		}
	}

	/**
	 * Returns the resolved financing configuration for a product, applying global
	 * defaults for any field the product leaves empty.
	 *
	 * @param int                         $product_id
	 * @param WC_Payment_Gateway|null     $gateway    Pass the gateway instance for
	 *                                                get_option() fallback; null reads
	 *                                                directly from the WC options row.
	 * @return array{type:DebiProFinancingType,monthly_interest:float,installments:int|null,max_installments:int|null,surcharge:float}
	 */
	public static function get_product_financing($product_id, $gateway = null) {
		$type    = get_post_meta($product_id, self::TYPE_KEY, true);
		$interest= get_post_meta($product_id, self::INTEREST_KEY, true);
		$install = get_post_meta($product_id, self::INSTALL_KEY, true);
		$max     = get_post_meta($product_id, self::MAX_INST_KEY, true);
		$surch   = get_post_meta($product_id, self::SURCHARGE_KEY, true);

		$opt = function ($key, $fallback) use ($gateway) {
			if ($gateway) {
				return $gateway->get_option($key, $fallback);
			}
			$s = get_option('woocommerce_debipro_settings', []);
			return isset($s[$key]) ? $s[$key] : $fallback;
		};

		if (DebiProFinancingType::tryFrom((string) $type) === null) {
			$type = $opt('default_type', DebiProFinancingType::Installment->value);
		}
		if (!is_numeric($interest)) {
			$interest = $opt('default_monthly_interest_percentage', 2);
		}
		if (!is_numeric($install) || (int) $install < 1) {
			$install = $opt('default_installments', '');
		}
		if (!is_numeric($max) || (int) $max < 1) {
			$max = $opt('default_max_installments', '');
		}
		if (!is_numeric($surch)) {
			$surch = $opt('default_surcharge_percentage', 0);
		}

		// installments wins over max_installments when both somehow resolve.
		if (is_numeric($install) && (int) $install >= 1) {
			$install_count = (int) $install;
			$max_count     = null;
		} elseif (is_numeric($max) && (int) $max >= 1) {
			$install_count = null;
			$max_count     = (int) $max;
		} else {
			$install_count = null;
			$max_count     = null;
		}

		return [
			'type'             => DebiProFinancingType::from( (string) $type ),
			'monthly_interest' => (float) $interest,
			'installments'     => $install_count,
			'max_installments' => $max_count,
			'surcharge'        => (float) $surch,
		];
	}
}
