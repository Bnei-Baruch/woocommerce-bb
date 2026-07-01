<?php
/**
 * Plugin Name: BB Payment Gateways
 * Plugin URI:  https://github.com/Bnei-Baruch/woocommerce-bb
 * Description: Bnei Baruch payment gateways: Pelecard EMV and PayPal via external_payments service.
 * Version:     1.0.0
 * Author:      Bnei Baruch
 * Text Domain: woocommerce-bb
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

add_action('plugins_loaded', 'bb_payments_init', 11);

function bb_payments_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-bb-gateway-base.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-bb-gateway-emv.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-bb-gateway-paypal.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'BB_Gateway_EMV';
        $gateways[] = 'BB_Gateway_PayPal';
        return $gateways;
    });
}
