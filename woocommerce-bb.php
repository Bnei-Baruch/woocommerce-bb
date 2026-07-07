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
add_action('admin_init', 'bb_migrate_subscription_payment_methods');

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

function bb_migrate_subscription_payment_methods() {
    if (get_option('bb_subscription_migration_done') || !function_exists('wcs_get_subscriptions')) {
        return;
    }

    $map = [
        'pelecard' => 'bb_emv',
        'paypal'   => 'bb_paypal',
    ];

    foreach ($map as $old_method => $new_method) {
        $subscriptions = wcs_get_subscriptions([
            'subscription_status'    => ['active', 'on-hold'],
            'payment_method'         => $old_method,
            'subscriptions_per_page' => -1,
        ]);

        foreach ($subscriptions as $subscription) {
            $subscription->set_payment_method($new_method);
            $subscription->save();
        }
    }

    update_option('bb_subscription_migration_done', true);
}
