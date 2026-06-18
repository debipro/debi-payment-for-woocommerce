<?php
/**
 * Debi product financing types.
 *
 * @package WooCommerce_Debi
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

enum DebiProFinancingType: string {
	case Subscription   = 'subscription';
	case Installment    = 'installment';
	case OneTimePayment = 'one_time';
}
