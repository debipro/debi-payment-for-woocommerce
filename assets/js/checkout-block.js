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
		installment_options: [],
		is_subscription: false,
		publishable_key: '',
		payment_flow: 'onsite',
		supports: ['products'],
	});

	var isRedirect     = settings.payment_flow === 'redirect';
	var isSubscription = settings.is_subscription === true;

	function DebiFields(props) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;

		if (!settings.publishable_key) {
			return createElement(
				'p',
				{ style: { color: '#c0392b', margin: '8px 0' } },
				'Para activar este medio de pago, terminá el proceso de vinculación en la configuración del plugin.'
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

		// Mount the Debi SDK payment element — only for onsite flow
		useEffect(function () {
			if (isRedirect) {
				return;
			}

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
				if (!isSubscription && !quotas) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: 'Por favor seleccioná el número de cuotas.',
					};
				}

				// Redirect flow: just pass quotas — server creates the hosted checkout
				if (isRedirect) {
					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: {
							paymentMethodData: {
								'debipro-cuotas': isSubscription ? '' : quotas,
							},
						},
					};
				}

				// Onsite flow: tokenize the card via Debi SDK before submitting
				if (!debiClientRef.current || !paymentElementRef.current) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: 'El SDK de Debi no está listo. Recargá la página.',
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
									? 'El servicio está temporalmente ocupado. Por favor, esperá un momento e intentá de nuevo.'
									: (result.error.message || 'Error al procesar la tarjeta.'),
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
									'debipro-cuotas': isSubscription ? '' : quotas,
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
								? 'El servicio está temporalmente ocupado. Por favor, esperá un momento e intentá de nuevo.'
								: ((err && err.message) || 'Error al procesar la tarjeta.'),
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
				'Seleccioná el número de cuotas'
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
			{ className: 'debipro-payment-fields', style: { padding: '16px !important' } },
			settings.description
				? createElement('p', { className: 'debipro-description' }, settings.description)
				: null,
			!isSubscription && options.length > 0
				? createElement(
					'p',
					null,
					createElement(
						'label',
						{ htmlFor: 'debipro-cuotas' },
						'Seleccioná el número de cuotas ',
						createElement('span', { className: 'required' }, '*')
					),
					createElement(
						'select',
						{
							id: 'debipro-cuotas',
							value: quotas,
							onChange: function (e) {
								setQuotas(e.target.value);
							},
							style: { width: '100%', marginTop: '4px' },
						},
						selectChildren
					)
				  )
				: null,
			isRedirect
				? createElement(
					'p',
					{ style: { marginTop: '12px', fontSize: '0.9em', color: '#666' } },
					'Al confirmar serás redirigido a Debi para completar el pago de forma segura.'
				  )
				: createElement('div', {
					ref: mountRef,
					id: 'debipro-payment-element',
					style: { marginTop: '12px' },
				  })
		);
	}

	registerPaymentMethod({
		name: 'debipro',
		label: settings.title,
		content: createElement(DebiFields, null),
		edit: createElement('div', null, settings.title),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: settings.title,
		supports: {
			features: settings.supports || ['products'],
		},
	});
})();
