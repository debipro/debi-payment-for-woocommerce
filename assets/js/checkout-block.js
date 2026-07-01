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

		var quotasState = useState('');
		var quotas = quotasState[0];
		var setQuotas = quotasState[1];

		// Refs hold the SDK instances across renders without causing re-renders
		var mountRef = useRef(null);
		var debiClientRef = useRef(null);
		var paymentElementRef = useRef(null);

		var onPaymentSetup = eventRegistration.onPaymentSetup;

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
				var options = settings.installment_options || [];
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
		}, [onPaymentSetup, quotas]);

		var options = settings.installment_options || [];

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

		return createElement(
			'div',
			{ className: 'debipro-payment-fields' },
			settings.description
				? createElement('p', { className: 'debipro-description' }, settings.description)
				: null,
			options.length > 0
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
				: null,
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
