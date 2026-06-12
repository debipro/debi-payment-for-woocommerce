(function ($) {
	'use strict';

	// Whether the SDK has already produced a token for this submission attempt.
	// Reset to false whenever the user retries or the checkout updates.
	var tokenReady = false;
	var debiClient = null;
	var paymentElement = null;

	function initDebi() {
		var container = document.getElementById('debipro-payment-element');
		if (!container || !window.Debi || !window.debipro_params || !window.debipro_params.publishable_key) {
			return;
		}

		// Guard against double-init if the DOM node didn't change
		if (debiClient) {
			return;
		}

		debiClient = new window.Debi(window.debipro_params.publishable_key);
		var elements = debiClient.elements({ locale: 'es-AR' });

		// Capture cards for recurring debit: expiration and CVV are not needed
		// and would otherwise be auto-detected as optional. Force them hidden.
		paymentElement = elements.create('payment-method', {
			defaultType: 'card',
			strict: true,
			methods: {
				card: {
					expiration: 'hidden',
					securityCode: 'hidden',
				},
			},
		});

		container.style.padding = '16px !important';
		paymentElement.mount(container);
	}

	$(function () {
		initDebi();

		// After WooCommerce re-renders the checkout (shipping change, coupon, etc.),
		// the payment fields are replaced in the DOM so we need to re-init.
		$(document.body).on('updated_checkout payment_method_selected', function () {
			tokenReady = false;
			debiClient = null;
			paymentElement = null;
			initDebi();
		});

		// WooCommerce fires checkout_place_order_{gateway_id} just before placing
		// the order. Returning false stops WC and gives us a chance to tokenize
		// asynchronously, then retrigger the form submit with the token in place.
		$('form.checkout').on('checkout_place_order_debipro', function () {
			if (tokenReady) {
				// Token is already set — allow WC to proceed, then reset for next attempt.
				tokenReady = false;
				return true;
			}

			if (!debiClient || !paymentElement) {
				// SDK not initialized (e.g. no public key configured). Let WC send
				// the form anyway; the server will return an error for the missing token.
				return true;
			}

			var $form = $(this);

			debiClient
				.confirmPaymentMethod(paymentElement)
				.then(function (result) {
					if (result.error) {
						showError($form, result.error.message);
						return;
					}

					var token = result.token;
					$form.find('#debipro-payment-method-token').val(token.id);
					$form.find('#debipro_card_last_4_digits').val(
						(token.card && token.card.last_four_digits) || ''
					);

					tokenReady = true;
					// Retrigger WC's checkout submit. WC will re-fire this event;
					// this time tokenReady is true so we return true and it proceeds.
					$form.trigger('submit');
				})
				.catch(function (err) {
					showError($form, (err && err.message) || 'Error al procesar la tarjeta.');
				});

			// Stop WC from submitting while we await tokenization
			return false;
		});
	});

	function showError($form, message) {
		$form.find('.debipro-sdk-error').remove();
		$('<div class="woocommerce-error debipro-sdk-error"><p>' + message + '</p></div>')
			.prependTo($form);
		$('html, body').animate({ scrollTop: $form.offset().top - 100 }, 400);
	}
})(jQuery);
