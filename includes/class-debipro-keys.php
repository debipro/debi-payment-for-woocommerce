<?php
/**
 * Pure helpers for reasoning about Debi API keys.
 *
 * Deliberately free of any WooCommerce dependency (it does NOT extend
 * WC_Payment_Gateway) so the environment/kind/validation logic can be unit
 * tested in isolation with Brain Monkey. The gateway class delegates here.
 *
 * Key shape (Debi):
 *   - Secret keys start with `sk_test_` (sandbox) or `sk_live_` (production).
 *   - Publishable keys start with `pk_test_` (sandbox) or `pk_live_` (production).
 *
 * The environment is INFERRED from the prefix — there is no separate
 * "sandbox mode" switch anymore.
 *
 * @package    WooCommerce_Debi
 * @author     Fernando del Peral <support@debi.pro>
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Stateless key reasoning helpers.
 */
class DEBIPRO_Keys {

	/**
	 * Infer the environment a key belongs to from its prefix.
	 *
	 * @param string $key Secret or publishable key.
	 * @return string 'test', 'live', or '' when it can't be determined.
	 */
	public static function environment( $key ) {
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( '' === $key ) {
			return '';
		}
		if ( 0 === strpos( $key, 'sk_test_' ) || 0 === strpos( $key, 'pk_test_' ) ) {
			return 'test';
		}
		if ( 0 === strpos( $key, 'sk_live_' ) || 0 === strpos( $key, 'pk_live_' ) ) {
			return 'live';
		}
		return '';
	}

	/**
	 * Whether a key is a secret (sk_) or publishable (pk_) key.
	 *
	 * @param string $key Key to inspect.
	 * @return string 'secret', 'publishable', or '' when unrecognised.
	 */
	public static function kind( $key ) {
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( 0 === strpos( $key, 'sk_' ) ) {
			return 'secret';
		}
		if ( 0 === strpos( $key, 'pk_' ) ) {
			return 'publishable';
		}
		return '';
	}

	/**
	 * Validate a secret/publishable key pair.
	 *
	 * Rules:
	 *   - An empty pair is allowed (gateway simply unconfigured).
	 *   - The secret must be an sk_ key with a recognisable environment.
	 *   - The publishable must be a pk_ key with a recognisable environment.
	 *   - When both are present they must share the same environment.
	 *
	 * @param string $secret      The secret key (sk_...).
	 * @param string $publishable The publishable key (pk_...).
	 * @return string|null Translated error message, or null when the pair is valid.
	 */
	public static function validate_pair( $secret, $publishable ) {
		$secret      = is_string( $secret ) ? trim( $secret ) : '';
		$publishable = is_string( $publishable ) ? trim( $publishable ) : '';

		if ( '' === $secret && '' === $publishable ) {
			return null;
		}

		if ( '' !== $secret ) {
			if ( 'secret' !== self::kind( $secret ) ) {
				return __( 'The Secret key must start with sk_test_ or sk_live_. It looks like you pasted a publishable (pk_) key.', 'debi-payment-for-woocommerce' );
			}
			if ( '' === self::environment( $secret ) ) {
				return __( 'The Secret key environment could not be determined. Use a key starting with sk_test_ (sandbox) or sk_live_ (production).', 'debi-payment-for-woocommerce' );
			}
		}

		if ( '' !== $publishable ) {
			if ( 'publishable' !== self::kind( $publishable ) ) {
				return __( 'The Publishable key must start with pk_test_ or pk_live_. Never paste your secret (sk_) key here.', 'debi-payment-for-woocommerce' );
			}
			if ( '' === self::environment( $publishable ) ) {
				return __( 'The Publishable key environment could not be determined. Use a key starting with pk_test_ (sandbox) or pk_live_ (production).', 'debi-payment-for-woocommerce' );
			}
		}

		if ( '' !== $secret && '' !== $publishable && self::environment( $secret ) !== self::environment( $publishable ) ) {
			return __( 'The Secret and Publishable keys belong to different environments (test vs live). Use a matching pair.', 'debi-payment-for-woocommerce' );
		}

		return null;
	}
}
