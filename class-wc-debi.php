<?php
/**
 * The core plugin class for Debi payment gateway.
 *
 * @package    WooCommerce_Debi
 * @author     Fernando del Peral <support@debi.pro>
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

/**
 * DEBIPRO_Payment_Gateway Payment Gateway
 *
 * @package    WooCommerce_Debi
 */
class DEBIPRO_Payment_Gateway extends WC_Payment_Gateway
{
    /**
     * Secret key (sk_test_... / sk_live_...). Server-side only; never exposed.
     *
     * @var string
     */
    private $secret_key;

    /**
     * Publishable key (pk_test_... / pk_live_...). Browser-safe.
     *
     * @var string
     */
    private $publishable_key;

    public function __construct()
    {
        $this->id = 'debipro';
        $this->method_title = __('Debi Payment', 'debi-payment-for-woocommerce');
        $this->title = __('Debi Payment', 'debi-payment-for-woocommerce');
        $this->has_fields = true;
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->secret_key = trim((string) $this->get_option('secret_key'));
        $this->publishable_key = trim((string) $this->get_option('publishable_key'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'debi-payment-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Custom Payment', 'debi-payment-for-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Method Title', 'debi-payment-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title', 'debi-payment-for-woocommerce'),
                'default' => __('Credit or debit card in installments', 'debi-payment-for-woocommerce'),
                'desc_tip' => true,
            ),
            'secret_key' => array(
                'title' => __('Secret key', 'debi-payment-for-woocommerce'),
                'type' => 'debipro_secret_key',
                'default' => '',
                'description' => __('Use sk_test_… for sandbox or sk_live_… for production — the environment is inferred from the prefix. Used server-side only; never exposed to the browser.', 'debi-payment-for-woocommerce'),
            ),
            'publishable_key' => array(
                'title' => __('Publishable key', 'debi-payment-for-woocommerce'),
                'type' => 'debipro_publishable_key',
                'default' => '',
                'description' => __('Safe to expose in the browser; the checkout card element uses it (pk_test_… / pk_live_…). Must match the secret key environment.', 'debi-payment-for-woocommerce'),
            ),
        );
    }

    /**
     * Render the secret-key settings row (input + env badge + Test + preview).
     *
     * @param string $key  Field key.
     * @param array  $data Field definition.
     * @return string HTML for a settings table row.
     */
    public function generate_debipro_secret_key_html($key, $data)
    {
        // The shared styles are emitted once, alongside the first key field.
        return self::field_styles() . $this->generate_key_field_html($key, $data, true);
    }

    /**
     * Render the publishable-key settings row (input + env badge + Test + preview).
     *
     * @param string $key  Field key.
     * @param array  $data Field definition.
     * @return string HTML for a settings table row.
     */
    public function generate_debipro_publishable_key_html($key, $data)
    {
        return $this->generate_key_field_html($key, $data, false);
    }

    /**
     * Shared CSS for the key fields, emitted only once per page render.
     *
     * @return string
     */
    private static function field_styles()
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        return '<style>'
            . '.debipro-env-badge{display:inline-block;margin-left:8px;vertical-align:middle;padding:2px 10px;border-radius:12px;font-weight:600;font-size:12px;line-height:20px;color:#fff;background:#646970;}'
            . '.debipro-env-badge.debipro-env-test{background:#996800;}'
            . '.debipro-env-badge.debipro-env-live{background:#1a7f37;}'
            . '.debipro-env-badge.debipro-env-error{background:#b32d2e;}'
            . '.debipro-env-badge.debipro-env-unknown{background:#646970;}'
            . '.debipro-test-result{margin-left:8px;font-weight:600;}'
            . '.debipro-key-preview{margin:8px 0;}'
            . '.debipro-key-preview code{background:#f0f0f1;padding:1px 6px;border-radius:3px;font-size:12px;}'
            . '</style>';
    }

    /**
     * Render a single Debi key field: input, live environment badge, a per-key
     * "Test" button with its own result area, the masked saved-value preview and
     * the links to the Debi developer dashboards.
     *
     * Markup is hydrated by assets/js/admin-settings.js.
     *
     * @param string $key       Field key (secret_key | publishable_key).
     * @param array  $data      Field definition.
     * @param bool   $is_secret Whether this is the secret key.
     * @return string HTML for a settings table row.
     */
    private function generate_key_field_html($key, $data, $is_secret)
    {
        $field_key  = $this->get_field_key($key);
        $value      = (string) $this->get_option($key);
        $preview    = self::mask_key($value);
        $target     = $is_secret ? 'secret' : 'publishable';
        $input_type = $is_secret ? 'password' : 'text';
        $title      = isset($data['title']) ? $data['title'] : '';
        $desc       = isset($data['description']) ? $data['description'] : '';

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($title); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo esc_html($title); ?></span></legend>
                    <input
                        type="<?php echo esc_attr($input_type); ?>"
                        name="<?php echo esc_attr($field_key); ?>"
                        id="<?php echo esc_attr($field_key); ?>"
                        value="<?php echo esc_attr($value); ?>"
                        class="input-text regular-input"
                        style="width:420px;"
                        autocomplete="off"
                    />
                    <span class="debipro-env-badge debipro-env-unknown" data-env-for="<?php echo esc_attr($target); ?>"><?php esc_html_e('Not configured', 'debi-payment-for-woocommerce'); ?></span>
                    <p style="margin:8px 0;">
                        <button type="button" class="button debipro-test-key" data-target="<?php echo esc_attr($target); ?>"><?php esc_html_e('Test', 'debi-payment-for-woocommerce'); ?></button>
                        <span class="debipro-test-result" data-result-for="<?php echo esc_attr($target); ?>" role="status" aria-live="polite"></span>
                    </p>
                    <?php if ($is_secret && '' !== $preview) : ?>
                        <p class="debipro-key-preview description">
                            <strong><?php esc_html_e('Saved secret key:', 'debi-payment-for-woocommerce'); ?></strong>
                            <code><?php echo esc_html($preview); ?></code>
                        </p>
                    <?php endif; ?>
                    <?php if ('' !== $desc) : ?>
                        <p class="description"><?php echo wp_kses_post($desc); ?></p>
                    <?php endif; ?>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: production dashboard link, 2: testing dashboard link. */
                            esc_html__('Find your keys — Production: %1$s · Testing: %2$s', 'debi-payment-for-woocommerce'),
                            '<a href="https://debi.pro/dashboard/developers" target="_blank" rel="noopener noreferrer">debi.pro/dashboard/developers</a>',
                            '<a href="https://debi-test.pro/dashboard/developers" target="_blank" rel="noopener noreferrer">debi-test.pro/dashboard/developers</a>'
                        );
                        ?>
                    </p>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Build a safe, partially-revealed preview of a stored key.
     *
     * The settings inputs are masked (password field), so this lets an admin
     * confirm WHICH key is saved without exposing it: the prefix + a few leading
     * characters and the last 4 are shown, the middle is elided.
     *
     * @param string $key Stored secret or publishable key.
     * @return string Masked preview, or '' when there is no key.
     */
    private static function mask_key($key)
    {
        $key = trim((string) $key);
        if ('' === $key) {
            return '';
        }
        $len = strlen($key);
        if ($len <= 12) {
            // Too short to reveal a middle safely: show first/last 2 only.
            $head = substr($key, 0, 2);
            $tail = substr($key, -2);
            return $head . str_repeat('•', max(1, $len - 4)) . $tail;
        }
        // Reveal the prefix + first chars (env is visible) and the last 4.
        return substr($key, 0, 10) . '…' . substr($key, -4);
    }

    /**
     * Validate the key pair before persisting so a wrong/mismatched key never
     * gets saved. Server-side mirror of the client-side checks in admin JS.
     *
     * @return bool True when settings were saved, false when validation blocked it.
     */
    public function process_admin_options()
    {
        $post_data   = $this->get_post_data();
        $secret      = isset($post_data[$this->get_field_key('secret_key')]) ? trim((string) $post_data[$this->get_field_key('secret_key')]) : '';
        $publishable = isset($post_data[$this->get_field_key('publishable_key')]) ? trim((string) $post_data[$this->get_field_key('publishable_key')]) : '';

        $error = DEBIPRO_Keys::validate_pair($secret, $publishable);
        if (null !== $error) {
            WC_Admin_Settings::add_error($error);
            // Keep the previously stored (valid) settings untouched.
            return false;
        }

        return parent::process_admin_options();
    }

    /**
     * AJAX: verify the typed keys are alive and report their environment.
     *
     * Tests the secret key (against the customers endpoint) and the publishable
     * key (against the account/payment_methods endpoint, which a publishable key
     * is allowed to read — the same call js.debi.pro makes). Runs on whatever is
     * currently typed in the form, before saving, so an admin can confirm
     * credentials without committing them. Protected by a nonce and the
     * manage_woocommerce capability.
     *
     * @return void
     */
    public static function ajax_test_connection()
    {
        check_ajax_referer('debipro_test_connection', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'debi-payment-for-woocommerce')), 403);
        }

        $secret      = isset($_POST['secret']) ? trim((string) wp_unslash($_POST['secret'])) : '';
        $publishable = isset($_POST['publishable']) ? trim((string) wp_unslash($_POST['publishable'])) : '';

        if ('' === $secret && '' === $publishable) {
            wp_send_json_error(array('message' => __('Enter at least one key before testing.', 'debi-payment-for-woocommerce')));
        }

        wp_send_json_success(array(
            'results' => array(
                'secret'      => self::probe_key($secret, 'secret', 'customers'),
                'publishable' => self::probe_key($publishable, 'publishable', 'account/payment_methods'),
            ),
        ));
    }

    /**
     * Human label for an inferred environment.
     *
     * @param string $environment 'test' | 'live'.
     * @return string
     */
    private static function env_label($environment)
    {
        return ('test' === $environment)
            ? __('TEST (sandbox)', 'debi-payment-for-woocommerce')
            : __('PRODUCTION', 'debi-payment-for-woocommerce');
    }

    /**
     * Probe a single key against an endpoint it is allowed to read.
     *
     * A 2xx (or a 404/405, which still proves authentication succeeded) means
     * the key is valid; a 401/403 means Debi rejected it.
     *
     * @param string $key           Key to test (may be empty → not tested).
     * @param string $expected_kind 'secret' | 'publishable'.
     * @param string $endpoint      Relative endpoint to GET.
     * @return array{tested:bool,ok:bool,environment:string,message:string}
     */
    private static function probe_key($key, $expected_kind, $endpoint)
    {
        if ('' === $key) {
            return array('tested' => false, 'ok' => false, 'environment' => '', 'message' => '');
        }

        if ($expected_kind !== DEBIPRO_Keys::kind($key)) {
            $message = ('secret' === $expected_kind)
                ? __('Not a secret key (must start with sk_test_ or sk_live_).', 'debi-payment-for-woocommerce')
                : __('Not a publishable key (must start with pk_test_ or pk_live_).', 'debi-payment-for-woocommerce');
            return array('tested' => true, 'ok' => false, 'environment' => '', 'message' => $message);
        }

        $environment = DEBIPRO_Keys::environment($key);
        if ('' === $environment) {
            return array('tested' => true, 'ok' => false, 'environment' => '', 'message' => __('The key environment could not be determined from its prefix.', 'debi-payment-for-woocommerce'));
        }

        $is_sandbox = ('test' === $environment);

        try {
            (new DEBIPRO_debi($key, $is_sandbox))->request($endpoint, array('method' => 'GET'));
        } catch (DEBIPRO_debiException $e) {
            $code = (int) $e->getCode();
            if (401 === $code || 403 === $code) {
                return array(
                    'tested'      => true,
                    'ok'          => false,
                    'environment' => $environment,
                    'message'     => __('Authentication failed: Debi rejected this key.', 'debi-payment-for-woocommerce'),
                );
            }
            // Authenticated but the endpoint did not allow this exact request:
            // the key itself is valid.
            if (404 !== $code && 405 !== $code) {
                return array(
                    'tested'      => true,
                    'ok'          => false,
                    'environment' => $environment,
                    'message'     => $e->getMessage(),
                );
            }
        }

        return array(
            'tested'      => true,
            'ok'          => true,
            'environment' => $environment,
            /* translators: %s is the environment label (TEST/PRODUCTION). */
            'message'     => sprintf(__('OK — %s.', 'debi-payment-for-woocommerce'), self::env_label($environment)),
        );
    }

    /**
     * Enqueue the admin settings script on the Debi gateway screen only.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public static function enqueue_admin_assets($hook)
    {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
        if ('debipro' !== $section) {
            return;
        }

        wp_enqueue_script(
            'debipro-admin-settings',
            DEBIPRO_PLUGIN_URL . 'assets/js/admin-settings.js',
            array('jquery'),
            DEBIPRO_PLUGIN_VERSION,
            true
        );
        wp_localize_script(
            'debipro-admin-settings',
            'debiproAdmin',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('debipro_test_connection'),
                'i18n'    => array(
                    'envTest'       => __('TEST (sandbox)', 'debi-payment-for-woocommerce'),
                    'envLive'       => __('PRODUCTION', 'debi-payment-for-woocommerce'),
                    'envUnknown'    => __('Not configured', 'debi-payment-for-woocommerce'),
                    'envMismatch'   => __('Keys belong to different environments', 'debi-payment-for-woocommerce'),
                    'secretInvalid' => __('Secret key must start with sk_test_ or sk_live_', 'debi-payment-for-woocommerce'),
                    'pubInvalid'    => __('Publishable key must start with pk_test_ or pk_live_', 'debi-payment-for-woocommerce'),
                    'testing'       => __('Testing…', 'debi-payment-for-woocommerce'),
                    'blockedSave'   => __('Fix the Debi keys before saving.', 'debi-payment-for-woocommerce'),
                    'enterKey'      => __('Enter a key before testing.', 'debi-payment-for-woocommerce'),
                ),
            )
        );
    }

    public function process_payment($order_id)
    {
        global $woocommerce;
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }

        // WooCommerce payment gateways handle nonce verification automatically
        // The nonce is verified by WooCommerce's process_payment() wrapper

        $items = $woocommerce->cart->get_cart();

        $product_id = null;
        $product_title = '';
        $monthly_interest_percentage = 0;
        $surcharge_percentage = 0;
        
        foreach ($items as $item => $values) {
            $_product = wc_get_product($values['data']->get_id());
            $product_title = $_product->get_title();
            $product_id = $_product->get_id();
            
            // Get financing custom fields from product
            $monthly_interest_percentage = get_post_meta($product_id, '_monthly_interest_percentage', true);
            $monthly_interest_percentage = is_numeric($monthly_interest_percentage) ? floatval($monthly_interest_percentage) : 0;
            
            $surcharge_percentage = get_post_meta($product_id, '_surcharge_percentage', true);
            $surcharge_percentage = is_numeric($surcharge_percentage) ? floatval($surcharge_percentage) : 0;
        }
        
        $name = $woocommerce->customer->get_billing_last_name() . ', ' . $woocommerce->customer->get_billing_first_name();
        $email = $woocommerce->customer->get_billing_email();

        // Single secret key; the environment is inferred from its prefix.
        $token = $this->secret_key;
        $is_sandbox = ('test' === DEBIPRO_Keys::environment($token));
        
        // Sanitize and validate input
        $quotas = isset($_POST[$this->id . '-cuotas']) ? absint(wp_unslash($_POST[$this->id . '-cuotas'])) : 0;
        
        if ($quotas < 1) {
            wc_add_notice(__('Invalid number of installments selected.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }
        
        // Apply surcharge to base amount before calculating financing
        $order_total = (float)$order->get_total();
        $base_amount = $order_total * (1 + $surcharge_percentage / 100);
        
        // Calculate final price using compound interest formula
        // First period has no interest, from second period onwards: base_amount * (1 + rate)^(t-1)
        if ($quotas == 1 || $monthly_interest_percentage == 0) {
            // No interest for single installment or when rate is 0
            $final_price = $base_amount;
        } else {
            // Compound interest: base_amount * (1 + rate)^(t-1)
            $rate = $monthly_interest_percentage / 100;
            $final_price = $base_amount * pow(1 + $rate, $quotas - 1);
        }
        
        $DNIoCUIL = isset($_POST['participant_id']) ? sanitize_text_field(wp_unslash($_POST['participant_id'])) : '';
        $number = isset($_POST[$this->id . '-payment_method_number']) ? sanitize_text_field(wp_unslash($_POST[$this->id . '-payment_method_number'])) : '';
        
        // Validate card number
        if (empty($number)) {
            wc_add_notice(__('Card number is required.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }
        
        // Basic card number validation (should be numeric and have reasonable length)
        $number = preg_replace('/\D/', '', $number);
        if (empty($number) || strlen($number) < 13 || strlen($number) > 19) {
            wc_add_notice(__('Invalid card number.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }

        update_post_meta($order_id, '_debipro_final_price', sanitize_text_field($final_price));
        update_post_meta($order_id, '_debipro_installment_count', sanitize_text_field($quotas));
        update_post_meta($order_id, '_debipro_installment_amount', sanitize_text_field($final_price / $quotas));
        update_post_meta($order_id, '_debipro_card_last_four', sanitize_text_field(substr($number, -4)));

        if (gmdate('j') >= 29) {
            $day_of_month = 1;
        } else {
            $day_of_month = gmdate('j');
        }


        // Save customer to Debi
        $response_customer = (new DEBIPRO_debi($token, $is_sandbox))->request('customers', [
            'method' => 'POST',
            'body' => [
                'name' => $name,
                'email' => $email,
                'identification_number' => $DNIoCUIL,
            ],
        ]);

        $data_customer = $response_customer['data'];
        $customer_id = $data_customer['id'];


        // Tokenize payment method
        $response_payment_method = (new DEBIPRO_debi($token, $is_sandbox))->request('payment_methods', [
            'method' => 'POST',
            'body' => [
                'type' => 'card',
                'card' => [
                    'number' => $number,
                ]
            ],
        ]);

        $data_payment_method = $response_payment_method['data'];
        $payment_method_id = $data_payment_method['id'];

        $request = (new DEBIPRO_debi($token, $is_sandbox))->request('subscriptions', [
            'method' => 'POST',
            'body' => [
                'amount' => $final_price / $quotas,
                'description' => 'Order ' . $order->id . ' - Product ' . $product_id . ' - ' . $product_title,
                'payment_method_id' => $payment_method_id,
                'interval_unit' => "monthly",
                'interval' => 1,
                'day_of_month' => $day_of_month,
                'count' => $quotas,
                'customer_id' => $customer_id,
            ],
        ]);

        // Save subscription_id for future updates
        $data = $request['data'];
        $subscription_id = $data['id'];

        if (empty($subscription_id)) {
            return array(
                'result' => 'failure',
                'redirect' => $this->get_return_url($order),
            );
        } else {

            if (!empty($subscription_id)) {
                update_post_meta($order_id, '_debipro_subscription_id', sanitize_text_field($subscription_id));
            }

            // This also reduces stock (if cancelled later, it automatically increases)
            $order->update_status('processing');

            // Remove cart
            $woocommerce->cart->empty_cart();
            
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
    }

    /**
     * Get formatted installment text
     *
     * @param int $count Number of installments
     * @param string $quota_amount Formatted quota amount
     * @param string $final_amount Formatted final amount
     * @param string $extra_text Additional text to append (e.g., "- DEBIT CARD ONLY")
     * @return string Formatted installment text
     */
    private function get_installment_text($count, $quota_amount, $final_amount, $extra_text = '') {
        // translators: %1$d is the installment count, %2$s is the installment amount, %3$s is the total amount
        $singular_text = __('%1$d installment of $ %2$s ($ %3$s)', 'debi-payment-for-woocommerce');
        // translators: %1$d is the installment count, %2$s is the installment amount, %3$s is the total amount
        $plural_text = __('%1$d installments of $ %2$s ($ %3$s)', 'debi-payment-for-woocommerce');
        
        $text = ($count == 1) ? $singular_text : $plural_text;
        
        $formatted = sprintf($text, $count, $quota_amount, $final_amount);
        
        if (!empty($extra_text)) {
            $formatted .= $extra_text;
        }
        
        return $formatted;
    }

    /**
     * Get formatted installment text for no interest options
     *
     * @param int $count Number of installments
     * @param string $quota_amount Formatted quota amount
     * @return string Formatted installment text
     */
    private function get_installment_no_interest_text($count, $quota_amount) {
        // translators: %1$d is the installment count, %2$s is the installment amount
        $singular_text = __('%1$d installment of $ %2$s (no interest)', 'debi-payment-for-woocommerce');
        // translators: %1$d is the installment count, %2$s is the installment amount
        $plural_text = __('%1$d installments of $ %2$s (no interest)', 'debi-payment-for-woocommerce');
        
        $text = ($count == 1) ? $singular_text : $plural_text;
        
        return sprintf($text, $count, $quota_amount);
    }

    public function payment_fields()
    {
            global $woocommerce;
            $amount = $woocommerce->cart->total;

            // Get product info and custom fields for financing
            $product_id = null;
            $product_title = '';
            $monthly_interest_percentage = 0;
            $minimum_installments_allowed = 1;
            $maximum_installments_allowed = 1;
            $surcharge_percentage = 0;

            $items = $woocommerce->cart->get_cart();
            foreach ($items as $item => $values) {
                $_product = wc_get_product($values['data']->get_id());
                $product_title = $_product->get_title();
                $product_id = $_product->get_id();
                
                // Get custom fields for financing
                $monthly_interest_percentage = get_post_meta($product_id, '_monthly_interest_percentage', true);
                $minimum_installments_allowed = get_post_meta($product_id, '_minimum_installments_allowed', true);
                $maximum_installments_allowed = get_post_meta($product_id, '_maximum_installments_allowed', true);
                $surcharge_percentage = get_post_meta($product_id, '_surcharge_percentage', true);
                
                // Set defaults if not configured
                $monthly_interest_percentage = is_numeric($monthly_interest_percentage) ? floatval($monthly_interest_percentage) : 0;
                $minimum_installments_allowed = is_numeric($minimum_installments_allowed) && $minimum_installments_allowed > 0 ? intval($minimum_installments_allowed) : 1;
                $maximum_installments_allowed = is_numeric($maximum_installments_allowed) && $maximum_installments_allowed > 0 ? intval($maximum_installments_allowed) : 1;
                $surcharge_percentage = is_numeric($surcharge_percentage) ? floatval($surcharge_percentage) : 0;
                
                // Ensure minimum is not greater than maximum
                if ($minimum_installments_allowed > $maximum_installments_allowed) {
                    $minimum_installments_allowed = $maximum_installments_allowed;
                }
            }
            
            // Apply surcharge to base amount before calculating financing
            $base_amount = $amount * (1 + $surcharge_percentage / 100);
?>

            <fieldset>
                <?php echo wp_kses_post($this->get_description()); ?>
                
                <p>
                    <label for="<?php echo esc_attr($this->id); ?>-cuotas"><?php esc_html_e('Select number of installments', 'debi-payment-for-woocommerce'); ?><span class="required">*</span></label>
                    <select id="<?php echo esc_attr($this->id); ?>-cuotas" name="<?php echo esc_attr($this->id); ?>-cuotas">
                        <option value="" disabled selected><?php esc_html_e('Select number of installments', 'debi-payment-for-woocommerce'); ?></option>
                        <?php
                        // Render installment options based on product's financing configuration
                        for ($i = $minimum_installments_allowed; $i <= $maximum_installments_allowed; $i++) {
                            // Calculate interest using compound interest formula
                            // First period has no interest, from second period onwards: base_amount * (1 + rate)^(t-1)
                            if ($i == 1 || $monthly_interest_percentage == 0) {
                                // No interest for single installment or when rate is 0
                                $final_amount = $base_amount;
                            } else {
                                // Compound interest: base_amount * (1 + rate)^(t-1)
                                $rate = $monthly_interest_percentage / 100;
                                $final_amount = $base_amount * pow(1 + $rate, $i - 1);
                            }
                            
                            $quota_amount = $final_amount / $i;
                            
                            $final_amount_formatted = number_format($final_amount, 2, ',', ' ');
                            $quota_amount_formatted = number_format($quota_amount, 2, ',', ' ');
                            
                            if ($monthly_interest_percentage == 0 && $surcharge_percentage == 0) {
                                // No interest and no surcharge option
                                $text = $this->get_installment_no_interest_text($i, $quota_amount_formatted);
                            } else {
                                // With interest or surcharge option
                                $text = $this->get_installment_text($i, $quota_amount_formatted, $final_amount_formatted);
                            }
                            ?>
                            <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($text); ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </p>

                <p class="form-row form-row-wide">
                    <label for="<?php echo esc_attr($this->id); ?>-payment"><?php esc_html_e('Enter your card number', 'debi-payment-for-woocommerce'); ?> <span class="required">*</span></label>
                    <input id="<?php echo esc_attr($this->id); ?>-payment" name="<?php echo esc_attr($this->id); ?>-payment_method_number"></input>
                </p>

                <div class="clear"></div>

            </fieldset>

<?php
    }
}
