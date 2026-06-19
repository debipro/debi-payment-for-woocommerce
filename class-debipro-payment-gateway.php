<?php
/**
 * The core plugin class for Debi payment gateway.
 *
 * @package    Debi_Payment_For_WooCommerce
 * @license    GPL-2.0-or-later
 * @author     Fernando del Peral <support@debi.pro>
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

/**
 * DEBIPRO_Payment_Gateway Payment Gateway
 *
 * @package    Debi_Payment_For_WooCommerce
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
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    /**
     * Enqueue the js.debi.pro card element + tokenization script on checkout.
     *
     * Loaded only when the gateway is enabled and a publishable key is set, so
     * the SDK is never pulled in on pages that don't need it.
     *
     * @return void
     */
    public function payment_scripts()
    {
        if (! function_exists('is_checkout')) {
            return;
        }
        if (! is_checkout() && ! is_checkout_pay_page()) {
            return;
        }
        if ('yes' !== $this->enabled || '' === $this->publishable_key) {
            return;
        }

        wp_enqueue_style(
            'debipro-checkout',
            DEBIPRO_PLUGIN_URL . 'assets/css/checkout-classic.css',
            array(),
            DEBIPRO_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'debipro-checkout',
            DEBIPRO_PLUGIN_URL . 'assets/js/checkout-classic.js',
            array('jquery'),
            DEBIPRO_PLUGIN_VERSION,
            true
        );
        wp_localize_script(
            'debipro-checkout',
            'debiproCheckout',
            array(
                'sdkUrl'         => 'https://js.debi.pro/v1/',
                'publishableKey' => $this->publishable_key,
                'locale'         => 'es-AR',
                'i18n'           => array(
                    'loadError'          => __('The card form could not be loaded. Please refresh and try again.', 'debi-payment-for-woocommerce'),
                    'genericError'       => __('The card could not be validated. Check the details and try again.', 'debi-payment-for-woocommerce'),
                    'rateLimitError'     => __('The payment service is temporarily busy. Please wait a moment and try again.', 'debi-payment-for-woocommerce'),
                    'notReady'           => __('The card form is not ready yet.', 'debi-payment-for-woocommerce'),
                    'recurringPayment'   => __('Monthly recurring payment', 'debi-payment-for-woocommerce'),
                    'perMonth'           => __('Month', 'debi-payment-for-woocommerce'),
                    'noInterest'         => __('interest-free', 'debi-payment-for-woocommerce'),
                    'total'              => __('Total', 'debi-payment-for-woocommerce'),
                    'selectInstallments'   => __('Select the number of installments', 'debi-payment-for-woocommerce'),
                    'installmentsLabel'    => __('Installments', 'debi-payment-for-woocommerce'),
                    'installmentsRequired' => __('Please select the number of installments.', 'debi-payment-for-woocommerce'),
                ),
            )
        );
    }

    /**
     * Read the financing configuration from the (single) product in the cart,
     * falling back to the gateway's global defaults for empty product fields.
     *
     * @return array{product_id:int,product_title:string,type:string,monthly_interest:float,surcharge:float,installments:int|null,max_installments:int|null}
     */
    public function get_cart_financing()
    {
        $financing = array(
            'product_id'       => 0,
            'product_title'    => '',
            'type'             => DebiProFinancingType::Installment,
            'monthly_interest' => 0.0,
            'surcharge'        => 0.0,
            'installments'     => null,
            'max_installments' => null,
        );

        if (! function_exists('WC') || ! WC()->cart) {
            return $financing;
        }

        foreach (WC()->cart->get_cart() as $values) {
            $product = wc_get_product($values['data']->get_id());
            if (! $product) {
                continue;
            }
            $pf = DEBIPRO_Product_Meta::get_product_financing($product->get_id(), $this);

            $financing['product_id']       = (int) $product->get_id();
            $financing['product_title']    = (string) $product->get_title();
            $financing['type']             = $pf['type'];
            $financing['monthly_interest'] = $pf['monthly_interest'];
            $financing['surcharge']        = $pf['surcharge'];
            $financing['installments']     = $pf['installments'];
            $financing['max_installments'] = $pf['max_installments'];
        }

        return $financing;
    }

    /**
     * Compound-interest installment price: the first period has no interest;
     * from the second onwards the base amount grows by the monthly rate.
     *
     * @param float $base_amount      Order total with any surcharge already applied.
     * @param ?int  $installments     Number of installments (>= 1) | null.
     * @param float $monthly_interest Monthly interest percentage.
     * @return float Total financed price.
     */
    private static function financed_total(float $base_amount, ?int $installments, float $monthly_interest)
    {
        if (!$installments || $installments <= 1 || $monthly_interest == 0.0) {
            return $base_amount;
        }
        $rate = $monthly_interest / 100;
        return $base_amount * pow(1 + $rate, $installments - 1);
    }

    /**
     * Build installment select options for the cart (used by Blocks checkout).
     *
     * @return array<int, array{value:int, label:string}>
     */
    public function get_installment_options_for_cart()
    {
        if (! function_exists('WC') || ! WC()->cart || WC()->cart->is_empty()) {
            return [];
        }

        $financing = $this->get_cart_financing();
        if ($financing['type'] === DebiProFinancingType::Subscription) {
            return [];
        }

        $amount           = (float) WC()->cart->total;
        $monthly_interest = $financing['monthly_interest'];
        $surcharge        = $financing['surcharge'];
        $base_amount      = $amount * (1 + $surcharge / 100);
        $options          = array();

        if (null !== $financing['installments']) {
            $options[] = self::format_installment_option(
                $financing['installments'],
                $monthly_interest,
                $surcharge,
                $base_amount
            );
        } else {
            for ($i = 1; $i <= $financing['max_installments']; $i++) {
                $options[] = self::format_installment_option($i, $monthly_interest, $surcharge, $base_amount);
            }
        }

        return $options;
    }

    /**
     * @return array{value:int, label:string}
     */
    private static function format_installment_option($index, $monthly_interest, $surcharge, $base_amount)
    {
        $final     = self::financed_total($base_amount, $index, $monthly_interest);
        $quota     = $final / $index;
        $quota_fmt = number_format($quota, 2, ',', ' ');
        $final_fmt = number_format($final, 2, ',', ' ');
        $plural    = $index > 1 ? 's' : '';

        if (0.0 === $monthly_interest && 0.0 === $surcharge) {
            $label = sprintf('%d cuota%s de $ %s (sin interés)', $index, $plural, $quota_fmt);
        } else {
            $label = sprintf('%d cuota%s de $ %s ($ %s)', $index, $plural, $quota_fmt, $final_fmt);
        }

        return array('value' => $index, 'label' => $label);
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
            'webhook_url' => array(
                'title' => __('Webhook URL', 'debi-payment-for-woocommerce'),
                'type' => 'debipro_webhook_url',
            ),
            'webhook_secret' => array(
                'title' => __('Webhook signing secret', 'debi-payment-for-woocommerce'),
                'type' => 'password',
                'default' => '',
                'description' => __('Paste the endpoint signing secret from your Debi developers dashboard. Used to verify that incoming webhook events were sent by Debi.', 'debi-payment-for-woocommerce'),
            ),
            'product_defaults_title' => array(
                'title'       => __('Product default values', 'debi-payment-for-woocommerce'),
                'type'        => 'title',
                'description' => __('These apply when the product does not have its own configuration in the Debi tab.', 'debi-payment-for-woocommerce'),
            ),
            'default_type' => array(
                'title'   => __('Product type', 'debi-payment-for-woocommerce'),
                'type'    => 'select',
                'default' => 'installment',
                'options' => array(
                    'installment'  => __('Installment', 'debi-payment-for-woocommerce'),
                    // 'subscription' => __('Subscription', 'debi-payment-for-woocommerce'),
                    'one_time'     => __('One-time payment', 'debi-payment-for-woocommerce'),
                ),
            ),
            'default_monthly_interest_percentage' => array(
                'title'             => __('Monthly interest (%)', 'debi-payment-for-woocommerce'),
                'type'              => 'number',
                'default'           => '2',
                'custom_attributes' => array('min' => 0, 'step' => '0.01'),
            ),
            'default_installments' => array(
                'title'             => __('Fixed installments', 'debi-payment-for-woocommerce'),
                'type'              => 'number',
                'default'           => '',
                'description'       => __('Fixed number of installments. Mutually exclusive with "Maximum installments".', 'debi-payment-for-woocommerce'),
                'desc_tip'          => true,
                'custom_attributes' => array('min' => 1, 'step' => 1),
            ),
            'default_max_installments' => array(
                'title'             => __('Maximum installments', 'debi-payment-for-woocommerce'),
                'type'              => 'number',
                'default'           => '',
                'description'       => __('The customer chooses between 1 and this value. Mutually exclusive with "Fixed installments".', 'debi-payment-for-woocommerce'),
                'desc_tip'          => true,
                'custom_attributes' => array('min' => 1, 'step' => 1),
            ),
            'default_surcharge_percentage' => array(
                'title'             => __('Surcharge (%)', 'debi-payment-for-woocommerce'),
                'type'              => 'number',
                'default'           => '0',
                'custom_attributes' => array('min' => 0, 'step' => '0.01'),
            ),
        );
    }

    /**
     * The REST URL Debi should POST webhook events to for this site.
     *
     * @return string
     */
    public static function webhook_url()
    {
        return rest_url('debipro/v1/webhook');
    }

    /**
     * Render the read-only Webhook URL row so the admin can copy it into the
     * Debi dashboard. It is a display-only field (no stored value).
     *
     * @param string $key  Field key.
     * @param array  $data Field definition.
     * @return string HTML for a settings table row.
     */
    public function generate_debipro_webhook_url_html($key, $data)
    {
        $title = isset($data['title']) ? $data['title'] : '';
        $url   = self::webhook_url();

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo esc_html($title); ?></th>
            <td class="forminp">
                <fieldset>
                    <input
                        type="text"
                        class="input-text regular-input"
                        style="width:420px;"
                        value="<?php echo esc_attr($url); ?>"
                        readonly
                        onfocus="this.select();"
                    />
                    <?php if (! self::is_local_site()) : ?>
                        <p style="margin:8px 0;">
                            <button type="button" class="button button-secondary debipro-setup-webhook"><?php esc_html_e('Test / Setup automatically', 'debi-payment-for-woocommerce'); ?></button>
                            <span class="debipro-test-result" data-result-for="webhook" role="status" aria-live="polite"></span>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Uses your secret key to create the endpoint at Debi (subscription.cancelled, subscription.finished) if it does not exist yet, and fills in the signing secret below. Save changes to persist it.', 'debi-payment-for-woocommerce'); ?>
                        </p>
                    <?php else : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: 1: production dashboard link, 2: testing dashboard link. */
                                esc_html__('This site is not publicly reachable, so automatic setup is disabled. Add this URL as a webhook endpoint (events: subscription.cancelled, subscription.finished) in your Debi dashboard — Production: %1$s · Testing: %2$s — then paste its signing secret below.', 'debi-payment-for-woocommerce'),
                                '<a href="https://debi.pro/dashboard/developers" target="_blank" rel="noopener noreferrer">debi.pro/dashboard/developers</a>',
                                '<a href="https://debi-test.pro/dashboard/developers" target="_blank" rel="noopener noreferrer">debi-test.pro/dashboard/developers</a>'
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Whether this site is a local / non-internet-reachable environment, where
     * Debi cannot deliver webhooks and automatic setup must be hidden.
     *
     * @return bool
     */
    public static function is_local_site()
    {
        if (function_exists('wp_get_environment_type') && 'local' === wp_get_environment_type()) {
            return true;
        }

        $host = '';
        if (function_exists('wp_parse_url')) {
            $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        }
        $host = strtolower($host);

        if ('' === $host || 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host) {
            return true;
        }
        foreach (array('.local', '.test', '.localhost', '.example') as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * AJAX: create (or reuse) the Debi webhook endpoint for this site and store
     * its signing secret. Uses whatever secret key is currently typed in the
     * form (falling back to the saved one). Protected by a nonce and the
     * manage_woocommerce capability.
     *
     * @return void
     */
    public static function ajax_setup_webhook()
    {
        check_ajax_referer('debipro_test_connection', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'debi-payment-for-woocommerce')), 403);
        }

        if (self::is_local_site()) {
            wp_send_json_error(array('message' => __('Automatic setup is unavailable on local sites.', 'debi-payment-for-woocommerce')));
        }

        $secret = isset($_POST['secret']) ? trim(sanitize_text_field(wp_unslash($_POST['secret']))) : '';
        if ('' === $secret) {
            $secret = self::get_gateway_setting('secret_key');
        }
        if ('secret' !== DEBIPRO_Keys::kind($secret)) {
            wp_send_json_error(array('message' => __('Enter a valid secret key first (sk_test_… or sk_live_…).', 'debi-payment-for-woocommerce')));
        }

        try {
            $result = \DebiPro\Webhook\WebhookInstaller::ensure($secret, self::webhook_url());
        } catch (\Debi\Exception\ExceptionInterface $e) {
            wp_send_json_error(array('message' => __('Debi rejected the request. Check the secret key and try again.', 'debi-payment-for-woocommerce')));
        } catch (\Throwable $e) {
            wp_send_json_error(array('message' => __('The webhook could not be set up. Please try again.', 'debi-payment-for-woocommerce')));
        }

        if ('' === $result['secret']) {
            wp_send_json_error(array('message' => __('The endpoint exists but its signing secret was not returned. Copy it from the Debi dashboard.', 'debi-payment-for-woocommerce')));
        }

        // Persist immediately so the secret is never lost even if the admin
        // navigates away without pressing "Save changes".
        self::update_gateway_setting('webhook_secret', $result['secret']);

        wp_send_json_success(array(
            'secret'  => $result['secret'],
            'created' => (bool) $result['created'],
            'message' => $result['created']
                ? __('Webhook created and signing secret saved.', 'debi-payment-for-woocommerce')
                : __('Existing webhook found; signing secret saved.', 'debi-payment-for-woocommerce'),
        ));
    }

    /**
     * Read a single value from the gateway settings option.
     *
     * @param string $key
     * @return string
     */
    private static function get_gateway_setting($key)
    {
        $settings = get_option('woocommerce_debipro_settings', array());
        if (!is_array($settings)) {
            return '';
        }
        return isset($settings[$key]) ? (string) $settings[$key] : '';
    }

    /**
     * Persist a single value into the gateway settings option.
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    private static function update_gateway_setting($key, $value)
    {
        $settings = get_option('woocommerce_debipro_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }
        $settings[$key] = $value;
        update_option('woocommerce_debipro_settings', $settings);
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

        $type_key    = $this->get_field_key('default_type');
        $install_key = $this->get_field_key('default_installments');
        $max_key     = $this->get_field_key('default_max_installments');
        $type        = isset($post_data[$type_key]) ? sanitize_text_field($post_data[$type_key]) : (string) $this->get_option('default_type', 'installment');

        if ('installment' === $type) {
            $install_raw = isset($post_data[$install_key]) ? trim((string) $post_data[$install_key]) : '';
            $max_raw     = isset($post_data[$max_key]) ? trim((string) $post_data[$max_key]) : '';
            $install_ok  = is_numeric($install_raw) && (int) $install_raw >= 1;
            $max_ok      = is_numeric($max_raw) && (int) $max_raw >= 1;

            if (!$install_ok && !$max_ok) {
                WC_Admin_Settings::add_error(
                    __('When the default product type is Installment, set either "Fixed installments" or "Maximum installments".', 'debi-payment-for-woocommerce')
                );
                return false;
            }

            if ($install_ok) {
                $_POST[$max_key] = '';
            } else {
                $_POST[$install_key] = '';
            }
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

        $secret      = isset($_POST['secret']) ? trim(sanitize_text_field(wp_unslash($_POST['secret']))) : '';
        $publishable = isset($_POST['publishable']) ? trim(sanitize_text_field(wp_unslash($_POST['publishable']))) : '';

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

        try {
            $status = self::probe_request($key, 'test' === $environment, $endpoint);
        } catch (\Debi\Exception\ExceptionInterface $e) {
            return array(
                'tested'      => true,
                'ok'          => false,
                'environment' => $environment,
                'message'     => __('Could not reach Debi to verify the key. Please try again.', 'debi-payment-for-woocommerce'),
            );
        }

        if (401 === $status || 403 === $status) {
            return array(
                'tested'      => true,
                'ok'          => false,
                'environment' => $environment,
                'message'     => __('Authentication failed: Debi rejected this key.', 'debi-payment-for-woocommerce'),
            );
        }

        // A 2xx — or a 404/405, which still proves the request authenticated —
        // means the key is valid.
        if (($status >= 200 && $status < 300) || 404 === $status || 405 === $status) {
            return array(
                'tested'      => true,
                'ok'          => true,
                'environment' => $environment,
                /* translators: %s is the environment label (TEST/PRODUCTION). */
                'message'     => sprintf(__('OK — %s.', 'debi-payment-for-woocommerce'), self::env_label($environment)),
            );
        }

        return array(
            'tested'      => true,
            'ok'          => false,
            'environment' => $environment,
            /* translators: %d is the HTTP status code returned by Debi. */
            'message'     => sprintf(__('Debi returned an unexpected response (HTTP %d).', 'debi-payment-for-woocommerce'), $status),
        );
    }

    /**
     * Issue an authenticated GET against a Debi endpoint and return the HTTP
     * status, used only to verify a key in the admin. Routes through the same
     * WordPress HTTP transport the SDK uses.
     *
     * @param string $key        Secret or publishable key.
     * @param bool   $is_sandbox Whether to target the sandbox API base.
     * @param string $endpoint   Relative endpoint (e.g. 'customers').
     * @return int HTTP status code.
     * @throws \Debi\Exception\ExceptionInterface On transport failure.
     */
    private static function probe_request($key, $is_sandbox, $endpoint)
    {
        $base = $is_sandbox ? \Debi\DebiClient::DEFAULT_SANDBOX_BASE : \Debi\DebiClient::DEFAULT_API_BASE;
        $url  = $base . '/v1/' . ltrim($endpoint, '/');

        $response = (new \DebiPro\Infrastructure\Http\WpDebiHttpClient(15))->send(
            'GET',
            $url,
            array(
                'Authorization' => 'Bearer ' . $key,
                'Accept'        => 'application/json',
            ),
            null
        );

        return (int) $response->status;
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page filter, no state change.
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
                    'webhookSetup'  => __('Setting up…', 'debi-payment-for-woocommerce'),
                    'webhookNeedsSecret' => __('Enter your secret key first.', 'debi-payment-for-woocommerce'),
                ),
            )
        );

        add_action('admin_footer', array(__CLASS__, 'print_installment_defaults_script'), 20);
    }

    /**
     * Inline script for default installment fields on the gateway settings screen.
     *
     * @return void
     */
    public static function print_installment_defaults_script()
    {
        if (!function_exists('WC')) {
            return;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways['debipro'] ?? null;
        if (!$gateway instanceof self) {
            return;
        }

        $type_id     = $gateway->get_field_key('default_type');
        $interest_id = $gateway->get_field_key('default_monthly_interest_percentage');
        $fixed_id    = $gateway->get_field_key('default_installments');
        $max_id      = $gateway->get_field_key('default_max_installments');
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            var typeId     = <?php echo wp_json_encode('#' . $type_id); ?>;
            var interestId = <?php echo wp_json_encode('#' . $interest_id); ?>;
            var fixedId    = <?php echo wp_json_encode('#' . $fixed_id); ?>;
            var maxId      = <?php echo wp_json_encode('#' . $max_id); ?>;
            var installMsg = <?php echo wp_json_encode(__('When the default product type is Installment, set either "Fixed installments" or "Maximum installments".', 'debi-payment-for-woocommerce')); ?>;

            function toggleInstallmentFields() {
                var isInstallment = $(typeId).val() === 'installment';
                $(interestId + ', ' + fixedId + ', ' + maxId).closest('tr').toggle(isInstallment);
            }

            function fieldHasValue(selector) {
                var value = $(selector).val();
                return value !== '' && value !== null && parseInt(value, 10) >= 1;
            }

            function syncInstallmentFields(changedSelector) {
                if (!fieldHasValue(changedSelector)) {
                    return;
                }
                if (changedSelector === fixedId) {
                    $(maxId).val('');
                } else if (changedSelector === maxId) {
                    $(fixedId).val('');
                }
            }

            $(document).on('change', typeId, toggleInstallmentFields);
            toggleInstallmentFields();

            if (fieldHasValue(fixedId) && fieldHasValue(maxId)) {
                $(maxId).val('');
            }

            $(document).on('input change', fixedId + ', ' + maxId, function() {
                syncInstallmentFields('#' + this.id);
            });

            $(typeId).closest('form').on('submit', function(e) {
                if ($(typeId).val() !== 'installment') {
                    return;
                }
                if (!fieldHasValue(fixedId) && !fieldHasValue(maxId)) {
                    e.preventDefault();
                    window.alert(installMsg);
                }
            });
        });
        </script>
        <?php
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }

        // WooCommerce verifies the checkout nonce (woocommerce-process_checkout) before
        // dispatching to process_payment(); our code reads the tokenised card data that
        // js.debi.pro places into hidden fields — never the PAN itself.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $token = isset($_POST['debipro-payment_method_token'])
            ? sanitize_text_field(wp_unslash($_POST['debipro-payment_method_token']))
            : '';
        if ('' === $token) {
            throw new \Exception(esc_html__('Please enter your card details before paying.', 'debi-payment-for-woocommerce'));
        }

        $financing                  = $this->get_cart_financing();
        $max_inst                   = $financing['max_installments'];
        $cuotas_raw                 = isset($_POST['debipro-cuotas']) ? absint(wp_unslash($_POST['debipro-cuotas'])) : 0;
        $user_selected_installments = $cuotas_raw > 0 ? $cuotas_raw : null;

        if ( $user_selected_installments !== null
            && $max_inst !== null
            && $user_selected_installments > $max_inst
        ) {
            throw new \Exception(esc_html__('Invalid number of installments selected.', 'debi-payment-for-woocommerce'));
        }

        $installments       = self::resolve_installments($order, $user_selected_installments);
        $base_amount        = (float) $order->get_total() * (1 + $financing['surcharge'] / 100);
        $final_price        = self::financed_total($base_amount, $installments, $financing['monthly_interest']);
        $installment_amount = self::resolve_installment_amount($final_price, $installments);

        $identification = isset($_POST['participant_id']) ? sanitize_text_field(wp_unslash($_POST['participant_id'])) : '';
        $last_four      = isset($_POST['debipro-card_last_four']) ? sanitize_text_field(wp_unslash($_POST['debipro-card_last_four'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $order->update_meta_data('_debipro_final_price', $final_price);
        $order->update_meta_data('_debipro_installment_count', $installments);
        $order->update_meta_data('_debipro_installment_amount', $installment_amount);
        if ('' !== $last_four) {
            $order->update_meta_data('_debipro_card_last_4_digits', $last_four);
        }

        try {
            $subscription_id = \DebiPro\Checkout\SubscriptionCreator::create(array(
                'order'                => $order,
                'payment_method_token' => $token,
                'installments'         => $installments,
                'installment_amount'   => $installment_amount,
                'description'          => sprintf(
                    'Order %d - Product %d - %s',
                    (int) $order_id,
                    $financing['product_id'],
                    $financing['product_title']
                ),
                'customer'             => array(
                    'name'                  => $order->get_billing_last_name() . ', ' . $order->get_billing_first_name(),
                    'email'                 => $order->get_billing_email(),
                    'identification_number' => $identification,
                ),
            ));
        } catch (\Debi\Exception\RateLimitException $e) {
            $this->log_error('Rate limit hit for order ' . (int) $order_id . ': ' . $e->getMessage());
            wc_add_notice(__('The payment service is temporarily busy. Please wait a moment and try again.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        } catch (\Debi\Exception\ExceptionInterface $e) {
            $this->log_error('Charge declined for order ' . (int) $order_id . ': ' . $e->getMessage());
            wc_add_notice(__('The payment was declined or could not be processed. Please check your card and try again.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        } catch (\Throwable $e) {
            $this->log_error('Charge failed for order ' . (int) $order_id . ': ' . $e->getMessage());
            wc_add_notice(__('We could not process the payment. Please try again in a moment.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }

        // Stored via the order data store (HPOS-safe): the webhook handler looks
        // the order up by this exact meta key.
        $order->update_meta_data('_debipro_subscription_id', $subscription_id);
        $order->save();

        // Moving to 'processing' also reduces stock; the webhook later moves it
        // to completed (subscription.finished) or cancelled (subscription.cancelled).
        $order->update_status('processing');

        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    /**
     * Log a gateway error through the WooCommerce logger when available.
     *
     * @param string $message
     * @return void
     */
    private function log_error($message)
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error($message, array('source' => 'debipro'));
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
        $financing           = $this->get_cart_financing();
        $cart_total          = (function_exists('WC') && WC()->cart) ? (float) WC()->cart->total : 0.0;
        $installment_options = $this->get_installment_options_for_cart();
        $f_json              = wp_json_encode(array(
            'type'             => $financing['type']->value,
            'monthly_interest' => $financing['monthly_interest'],
            'surcharge'        => $financing['surcharge'],
            'installments'     => $financing['installments'],
            'max_installments' => $financing['max_installments'],
        ));
        $options_json        = wp_json_encode($installment_options);
?>

            <fieldset class="debipro-payment-fields">
                <?php echo wp_kses_post($this->get_description()); ?>

                <?php // checkout-classic.js renders the installment plan UI here, reading fresh data from data attributes. ?>
                <div id="debipro-installment-ui"
                     data-financing="<?php echo esc_attr($f_json); ?>"
                     data-cart-total="<?php echo esc_attr($cart_total); ?>"
                     data-installment-options="<?php echo esc_attr($options_json); ?>">
                </div>

                <?php // js.debi.pro mounts the secure card element here (see assets/js/checkout-classic.js). ?>
                <div id="debipro-card-element" class="debipro-card-element"></div>
                <span id="debipro-card-errors" class="debipro-card-errors" role="alert" aria-live="polite"></span>

                <input type="hidden" id="debipro-payment-method-token" name="debipro-payment_method_token" value="" />
                <input type="hidden" id="debipro_card_last_4_digits" name="debipro-card_last_four" value="" />
                <input type="hidden" id="debipro-cuotas" name="<?php echo esc_attr($this->id); ?>-cuotas" value="" />

                <div class="clear"></div>

            </fieldset>

<?php
    }

    /**
     * Resolve the number of installments for a subscription based on the product
     * financing configuration and the customer-requested count.
     *
     * - payment type    → always 1 (single charge, no plan).
     * - subscription with a fixed installment count on the product → use that value.
     * - subscription with a max_installments cap → use $requested, throwing if it
     *   exceeds the cap.
     * - subscription with no constraint → use $requested as-is (0 = open-ended).
     */
    private static function resolve_installments( WC_Order $order, ?int $requested ): ?int {
        $items     = $order->get_items();
        $last_item = $items ? $items[ array_key_last( $items ) ] : false;

        if(!$last_item) {
            throw new \Exception(
                esc_html__( 'Could not determine product from order; order contains no valid items.', 'debi-payment-for-woocommerce' )
            );
        }

        $product_id     = (int) $last_item->get_product_id();
        $financing      = \DEBIPRO_Product_Meta::get_product_financing( $product_id );
        $financing_type = $financing['type'];

        if($financing_type == DebiProFinancingType::Subscription)
            return null;

        if($financing_type == DebiProFinancingType::OneTimePayment)
            return 1;

        if($financing_type == DebiProFinancingType::Installment) {
            $fixed_installments = $financing['installments'];
            $max_installments   = $financing['max_installments'];

            if ( $fixed_installments !== null )
                return $fixed_installments;

            if ( $requested === null ) {
                throw new \Exception(
                    esc_html__( 'Requested number of installments was not provided.', 'debi-payment-for-woocommerce' )
                );
            }

            if( $max_installments !== null && $requested < $max_installments)
                return $requested;                
            
            throw new \Exception(
                sprintf(
                /* translators: 1: requested installment count, 2: product maximum installments. */
                esc_html__( 'Requested installments (%1$d) exceed the product maximum (%2$d).', 'debi-payment-for-woocommerce' ),
                (int) $requested,
                (int) $max_installments)
            );
        }
        
        throw new \Exception(esc_html__( 'Product financing configuration is invalid.', 'debi-payment-for-woocommerce' ));
    }

    private static function resolve_installment_amount(float $final_price, ?int $installments) {
        if (!$installments) {
            return $final_price;
        }

        return $final_price / max(1, $installments);
    }
}
