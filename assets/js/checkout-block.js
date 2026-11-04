(function () {
	'use strict';

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var createElement = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var getSetting = window.wc.wcSettings.getSetting;

	var settings = getSetting('debipro_data', {
		title: 'Debi Payment',
		description: '',
		icon: '',
		installment_options: [],
		publishable_key: '',
		checkout_mode: 'embedded',
		session_type: 'installment',
		amount_mode: 'fixed',
		product_id: 0,
		cart_total: 0,
		installments_url: '',
		supports: ['products'],
		i18n: {},
	});

	var i18n = settings.i18n || {};

	function DebiFields(props) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;

		if (!settings.publishable_key) {
			return createElement(
				'p',
				{ style: { color: '#c0392b', margin: '8px 0' } },
				i18n.linkRequired || 'To enable this payment method, complete the linking process in the plugin settings.'
			);
		}

		// Redirect mode (payment / installment products): the card is captured on
		// Debi's hosted page, so we show a notice instead of the embedded element.
		// No tokenization here; process_payment creates the session and returns the
		// redirect URL, which WooCommerce Blocks follows after Place Order.
		// This early return (before the SDK hooks) is safe because checkout_mode is
		// constant for the component's lifetime, matching the publishable_key guard.
		if (settings.checkout_mode === 'redirect') {
			return createElement(
				'div',
				{ className: 'debipro-payment-fields' },
				settings.description
					? createElement('p', { className: 'debipro-description' }, settings.description)
					: null,
				createElement(
					'p',
					{ className: 'debipro-redirect-note' },
					i18n.redirectNotice || 'You will be redirected to Debi to complete your payment securely.'
				)
			);
		}

		var isEditable = settings.amount_mode === 'editable';
		var isInstallment = settings.session_type === 'installment';
		var isSubscription = settings.session_type === 'subscription';

		var quotasState = useState('');
		var quotas = quotasState[0];
		var setQuotas = quotasState[1];

		// Customer-defined amount (free value, no floor) for editable products.
		var amountState = useState('');
		var amount = amountState[0];
		var setAmount = amountState[1];

		// Installment plans, refreshed live from the Debi REST endpoint so the
		// cuotas and per-installment figures track the entered amount.
		var optionsState = useState(settings.installment_options || []);
		var options = optionsState[0];
		var setOptions = optionsState[1];

		// Refs hold the SDK instances across renders without causing re-renders
		var mountRef = useRef(null);
		var debiClientRef = useRef(null);
		var paymentElementRef = useRef(null);

		var onPaymentSetup = eventRegistration.onPaymentSetup;

		// (Re)load installment plans for the current amount. Runs on mount (so the
		// selector is correct even if the server-rendered options were stale) and
		// whenever the editable amount changes, debounced. Non-installment products
		// have no selector, so this is a no-op for them.
		useEffect(function () {
			if (!isInstallment || !settings.installments_url || !settings.product_id) {
				return;
			}
			var controller =
				typeof AbortController !== 'undefined' ? new AbortController() : null;
			var handle = setTimeout(function () {
				var url =
					settings.installments_url +
					(settings.installments_url.indexOf('?') >= 0 ? '&' : '?') +
					'product_id=' +
					encodeURIComponent(settings.product_id);
				var amt = parseFloat(amount);
				if (!isNaN(amt) && amt > 0) {
					url += '&amount=' + encodeURIComponent(amt);
				}
				fetch(url, {
					credentials: 'same-origin',
					signal: controller ? controller.signal : undefined,
				})
					.then(function (r) {
						return r.json();
					})
					.then(function (data) {
						var opts = (data && data.options) || [];
						setOptions(opts);
						setQuotas(function (prev) {
							var stillValid = opts.some(function (o) {
								return String(o.value) === String(prev);
							});
							if (stillValid) {
								return prev;
							}
							return opts.length ? String(opts[0].value) : '';
						});
					})
					.catch(function () {
						/* keep the previous options on network error */
					});
			}, 300);
			return function () {
				clearTimeout(handle);
				if (controller) {
					controller.abort();
				}
			};
		}, [amount, isInstallment]);

		// Mount the Debi SDK payment element.
		useEffect(function () {
			var publishableKey = settings.publishable_key;
			if (!publishableKey || !window.Debi) {
				return;
			}

			var debi = new window.Debi(publishableKey);
			var elements = debi.elements({ locale: 'es-AR' });

			// Capture cards for recurring debit: expiration and CVV are not needed
			// and would otherwise be auto-detected as optional. Force them hidden.
			var paymentElement = elements.create('payment-method', {
				defaultType: 'card',
				strict: true,
				methods: {
					card: {
						expiration: 'hidden',
						securityCode: 'hidden',
					},
				},
			});

			if (mountRef.current) {
				paymentElement.mount(mountRef.current);
			}

			debiClientRef.current = debi;
			paymentElementRef.current = paymentElement;

			return function () {
				paymentElement.unmount();
				paymentElementRef.current = null;
				debiClientRef.current = null;
			};
		}, []);

		// Register the payment setup handler. onPaymentSetup supports returning a
		// Promise, so we can do the async SDK tokenization here before WC submits.
		useEffect(function () {
			var unsubscribe = onPaymentSetup(function () {
				if (isEditable) {
					var entered = parseFloat(amount);
					if (isNaN(entered) || entered <= 0) {
						return {
							type: emitResponse.responseTypes.ERROR,
							message: i18n.amountRequired || 'Please enter a valid amount.',
						};
					}
				}

				if (options.length > 0 && !quotas) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: i18n.installmentsRequired || 'Please select the number of installments.',
					};
				}

				if (!debiClientRef.current || !paymentElementRef.current) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: i18n.notReady || 'The card form is not ready yet.',
					};
				}

				return debiClientRef.current
					.confirmPaymentMethod(paymentElementRef.current)
					.then(function (result) {
						if (result.error) {
							var raw = result.error.raw || {};
							var isRateLimit = raw.status === 429 || raw.statusCode === 429;
							return {
								type: emitResponse.responseTypes.ERROR,
								message: isRateLimit
									? (i18n.rateLimitError || 'The payment service is temporarily busy. Please wait a moment and try again.')
									: (result.error.message || i18n.genericError || 'The card could not be validated. Check the details and try again.'),
							};
						}
						var lastFour =
							(result.token.card && result.token.card.last_four_digits) || '';
						return {
							type: emitResponse.responseTypes.SUCCESS,
							meta: {
								paymentMethodData: {
									'debipro-payment_method_token': result.token.id,
									'debipro-card_last_four': lastFour,
									'debipro-cuotas': quotas,
									'debipro-amount': isEditable ? amount : '',
								},
							},
						};
					})
					.catch(function (err) {
						var raw = (err && err.raw) || {};
						var isRateLimit = (err && (err.status === 429 || err.statusCode === 429)) ||
							raw.status === 429 || raw.statusCode === 429;
						return {
							type: emitResponse.responseTypes.ERROR,
							message: isRateLimit
								? (i18n.rateLimitError || 'The payment service is temporarily busy. Please wait a moment and try again.')
								: ((err && err.message) || i18n.genericError || 'The card could not be validated. Check the details and try again.'),
						};
					});
			});
			return unsubscribe;
		}, [onPaymentSetup, quotas, amount, options, isEditable]);

		var selectChildren = [
			createElement(
				'option',
				{ key: '__placeholder', value: '' },
				i18n.selectInstallments || 'Select the number of installments'
			),
		].concat(
			options.map(function (opt) {
				return createElement(
					'option',
					{ key: opt.value, value: opt.value },
					opt.label
				);
			})
		);

		var amountField = isEditable
			? createElement(
				'p',
				{ className: 'debipro-amount-row' },
				createElement(
					'label',
					{ htmlFor: 'debipro-amount', className: 'debipro-amount-label' },
					isSubscription
						? i18n.monthlyAmountLabel || 'Monthly amount'
						: i18n.amountLabel || 'Amount'
				),
				createElement('input', {
					id: 'debipro-amount',
					type: 'number',
					step: '0.01',
					min: '0',
					className: 'debipro-amount-input',
					value: amount,
					onChange: function (e) {
						setAmount(e.target.value);
					},
				})
			  )
			: null;

		var installmentField =
			isInstallment && options.length > 0
				? createElement(
					'p',
					null,
					createElement(
						'select',
						{
							id: 'debipro-cuotas',
							className: 'debipro-installments-select',
							value: quotas,
							onChange: function (e) {
								setQuotas(e.target.value);
							},
						},
						selectChildren
					)
				  )
				: null;

		return createElement(
			'div',
			{ className: 'debipro-payment-fields' },
			settings.description
				? createElement('p', { className: 'debipro-description' }, settings.description)
				: null,
			amountField,
			installmentField,
			createElement('div', {
				ref: mountRef,
				id: 'debipro-payment-element',
				style: { marginTop: '12px' },
			})
		);
	}

	function PaymentLabel() {
		return createElement(
			'span',
			{ className: 'debipro-payment-label' },
			settings.icon
				? createElement('img', {
					className: 'debipro-payment-icon',
					src: settings.icon,
					alt: settings.title,
				})
				: null,
		);
	}

	registerPaymentMethod({
		name: 'debipro',
		label: createElement(PaymentLabel, null),
		content: createElement(DebiFields, null),
		edit: createElement(PaymentLabel, null),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: settings.title,
		supports: {
			features: settings.supports || ['products'],
		},
	});
})();
