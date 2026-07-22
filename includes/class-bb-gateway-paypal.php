<?php
defined('ABSPATH') || exit;

class BB_Gateway_PayPal extends BB_Gateway_Base {

    public function __construct() {
        $this->id                 = 'bb_paypal';
        $this->method_title       = __('BB PayPal', 'woocommerce-bb');
        $this->method_description = __('PayPal payment via external_payments service.', 'woocommerce-bb');
        $this->has_fields         = false;
        $this->supports           = [
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'multiple_subscriptions',
        ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_setting('title', 'PayPal');
        $this->description = $this->get_setting('description', '');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_' . $this->id . '_return', [$this, 'handle_return']);
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [$this, 'scheduled_subscription_payment'], 10, 2);
    }

    public function init_form_fields() {
        $this->form_fields = array_merge([
            'enabled' => [
                'title'   => __('Enable/Disable', 'woocommerce-bb'),
                'type'    => 'checkbox',
                'label'   => __('Enable BB PayPal', 'woocommerce-bb'),
                'default' => 'no',
            ],
            'title' => [
                'title'   => __('Title', 'woocommerce-bb'),
                'type'    => 'text',
                'default' => 'PayPal',
            ],
            'description' => [
                'title'   => __('Description', 'woocommerce-bb'),
                'type'    => 'textarea',
                'default' => '',
            ],
        ], $this->shared_form_fields());
    }

    public function process_payment($order_id) {
        $order    = wc_get_order($order_id);
        $user_key = $this->generate_user_key();
        $this->save_user_key($order, $user_key);

        $payload = $this->build_payload($order, $user_key);

        if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
            $payload['IsRecurring'] = true;
        }

        try {
            $redirect_url = $this->post_to_gateway($this->base_url() . '/paypal/new', $payload);
        } catch (Exception $e) {
            wc_add_notice(__('Payment error: ', 'woocommerce-bb') . $e->getMessage(), 'error');
            return ['result' => 'fail'];
        }

        return [
            'result'   => 'success',
            'redirect' => $redirect_url,
        ];
    }

    public function handle_return() {
        $action      = sanitize_key($_GET['action'] ?? '');
        $order_id    = absint($_GET['order_id'] ?? 0);
        $txn_id      = sanitize_text_field($_GET['transaction_id'] ?? '');
        $vault_token = sanitize_text_field($_GET['vault_token'] ?? '');

        $order = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            wc_add_notice(__('Order not found.', 'woocommerce-bb'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        if ($action === 'good') {
            if ($vault_token) {
                $order->update_meta_data('_bb_paypal_vault_token', $vault_token);
                $order->save();
            }

            $order->payment_complete($txn_id ?: null);
            $order->add_order_note(sprintf(
                __('Payment completed via %s. Transaction ID: %s', 'woocommerce-bb'),
                $this->method_title,
                $txn_id
            ));
            wp_redirect($order->get_checkout_order_received_url());
            exit;
        }

        if ($action === 'cancel') {
            $order->update_status('cancelled', __('Customer cancelled payment.', 'woocommerce-bb'));
            wc_add_notice(__('Payment cancelled.', 'woocommerce-bb'), 'notice');
            wp_redirect($order->get_cancel_order_url_raw());
            exit;
        }

        $order->update_status('failed', __('Payment failed.', 'woocommerce-bb'));
        wc_add_notice(__('Payment failed. Please try again.', 'woocommerce-bb'), 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    public function scheduled_subscription_payment($amount_to_charge, $order) {
        $subscription_id = get_post_meta($order->get_id(), '_subscription_renewal', true);
        $subscription    = $subscription_id ? wc_get_order($subscription_id) : null;
        $parent_order_id = $subscription ? $subscription->get_parent_id() : 0;

        if (!$parent_order_id) {
            $order->update_status('failed', __('Subscription renewal: parent order not found.', 'woocommerce-bb'));
            return;
        }

        $parent_order = wc_get_order($parent_order_id);

        $token = $parent_order ? $parent_order->get_meta('_bb_paypal_vault_token') : '';

        if (!$token) {
            $order->update_status('failed', __('Subscription renewal: no PayPal token found.', 'woocommerce-bb'));
            return;
        }

        $user_key = $this->generate_user_key();
        $this->save_user_key($order, $user_key);

        $is_donation = $this->order_has_category($order->get_id(), 'donation');

        $payload = [
            'UserKey'      => $user_key,
            'GoodURL'      => $order->get_checkout_order_received_url(),
            'ErrorURL'     => wc_get_checkout_url(),
            'CancelURL'    => wc_get_checkout_url(),
            'Name'         => $order->get_formatted_billing_full_name(),
            'Price'        => (float) $amount_to_charge,
            'Currency'     => $this->map_currency(get_woocommerce_currency()),
            'Email'        => $order->get_billing_email(),
            'Phone'        => $this->order_phone($order),
            'Street'       => $order->get_billing_address_1(),
            'City'         => '',
            'Country'      => $order->get_billing_country(),
            'Details'      => $this->order_details($order),
            'SKU'          => $this->order_sku($order),
            'VAT'          => $is_donation ? 'Y' : 'N',
            'Installments' => 1,
            'Language'     => $this->map_language(),
            'Reference'    => $this->get_setting('reference_prefix') . $order->get_order_number(),
            'Organization' => $this->org(),
            'IsVisual'     => false,
            'Token'        => $token,
            'IsRecurring'  => true,
            'TaxType'      => $is_donation ? (string) $parent_order->get_meta('tax_customer_type') : '',
            'TaxId'        => $is_donation ? (string) $parent_order->get_meta('tax_id_code') : '',
        ];

        $response = wp_remote_post($this->base_url() . '/paypal/charge', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            $order->update_status('failed', __('Subscription renewal: gateway error: ', 'woocommerce-bb') . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['status']) && $body['status'] === 'success') {
            $capture_id = $body['capture_id'] ?? '';
            $order->payment_complete($capture_id ?: null);
            $order->add_order_note(sprintf(
                __('PayPal subscription renewal completed. Capture ID: %s', 'woocommerce-bb'),
                $capture_id
            ));
        } else {
            $error = $body['error'] ?? __('Unknown error', 'woocommerce-bb');
            $order->update_status('failed', __('Subscription renewal failed: ', 'woocommerce-bb') . $error);
        }
    }
}
