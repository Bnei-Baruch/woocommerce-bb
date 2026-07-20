<?php
defined('ABSPATH') || exit;

class BB_Gateway_EMV extends BB_Gateway_Base {

    public function __construct() {
        $this->id                 = 'bb_emv';
        $this->method_title       = __('BB Credit Card (EMV)', 'woocommerce-bb');
        $this->method_description = __('Credit card payment via Pelecard EMV terminal.', 'woocommerce-bb');
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

        $this->title       = $this->get_setting('title', __('Credit Card', 'woocommerce-bb'));
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
                'label'   => __('Enable BB Credit Card (EMV)', 'woocommerce-bb'),
                'default' => 'no',
            ],
            'title' => [
                'title'   => __('Title', 'woocommerce-bb'),
                'type'    => 'text',
                'default' => __('Credit Card', 'woocommerce-bb'),
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
            $payload['CreateToken'] = 'True';
        }

        $is_donation = $payload['VAT'] === 'Y';
        $debug = sprintf(
            'DEBUG: VAT=%s | QAME_WTAXNUMEXPL=%s | QAMS_VATNUM=%s',
            $payload['VAT'],
            $is_donation ? var_export($order->get_meta('tax_customer_type'), true) : 'n/a',
            $is_donation ? var_export($order->get_meta('tax_id_code'), true) : 'n/a'
        );
        wc_add_notice($debug, 'error');
        return ['result' => 'fail'];

        try {
            $redirect_url = $this->post_to_gateway($this->base_url() . '/emv/new', $payload);
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
        $action   = sanitize_key($_GET['action'] ?? '');
        $order_id = absint($_GET['order_id'] ?? 0);
        $txn_id   = sanitize_text_field($_GET['transaction_id'] ?? '');
        $token    = sanitize_text_field($_GET['token'] ?? '');
        $auth_no  = sanitize_text_field($_GET['authNo'] ?? '');

        $order = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            wc_add_notice(__('Order not found.', 'woocommerce-bb'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        if ($action === 'good') {
            if ($token) {
                $order->update_meta_data('_bb_token', $token);
                $order->update_meta_data('_bb_auth_no', $auth_no);
                $order->save();

                if ($order->get_user_id()
                    && function_exists('wcs_order_contains_subscription')
                    && wcs_order_contains_subscription($order)
                ) {
                    $last4    = substr(sanitize_text_field($_GET['credit_card_number'] ?? ''), -4);
                    $exp_date = sanitize_text_field($_GET['credit_card_exp_date'] ?? '');
                    $brand    = sanitize_text_field($_GET['credit_card_brand'] ?? '');
                    $this->save_payment_token($token, $order->get_user_id(), $last4, $exp_date, $brand);
                }
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
        $token        = $parent_order ? $parent_order->get_meta('_bb_token') : '';
        $auth_no      = $parent_order ? $parent_order->get_meta('_bb_auth_no') : '';

        // Fallback: old pelecard plugin stored token in _transaction_data
        if (!$token && $parent_order) {
            $tx_data = $parent_order->get_meta('_transaction_data');
            $token   = $tx_data['Token'] ?? '';
            $auth_no = $tx_data['DebitApproveNumber'] ?? '';
        }

        if (!$token) {
            $order->update_status('failed', __('Subscription renewal: no payment token found.', 'woocommerce-bb'));
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
            'City'         => $order->get_billing_city(),
            'Country'      => $order->get_billing_country(),
            'Details'      => sprintf(__('Order #%s', 'woocommerce-bb'), $order->get_order_number()),
            'SKU'          => $this->order_sku($order),
            'VAT'              => $is_donation ? 'Y' : 'N',
            'QAME_WTAXNUMEXPL' => $is_donation ? (string) $order->get_meta('tax_customer_type') : '',
            'QAMS_VATNUM'      => $is_donation ? (string) $order->get_meta('tax_id_code') : '',
            'Installments' => 1,
            'Language'     => $this->map_language(),
            'Reference'    => $this->get_setting('reference_prefix') . $order->get_order_number(),
            'Organization' => $this->org(),
            'IsVisual'     => false,
            'Token'        => $token,
            'ApprovalNo'   => $auth_no,
            'IsRecurring'  => true,
        ];

        $response = wp_remote_post($this->base_url() . '/emv/charge', [
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
            $data   = !empty($body['data']) ? json_decode($body['data'], true) : [];
            $txn_id = $data['transaction_id'] ?? '';
            $order->payment_complete($txn_id ?: null);
            $order->add_order_note(sprintf(
                __('Subscription renewal payment completed. Transaction ID: %s', 'woocommerce-bb'),
                $txn_id
            ));
        } else {
            $error = $body['error'] ?? __('Unknown error', 'woocommerce-bb');
            $order->update_status('failed', __('Subscription renewal failed: ', 'woocommerce-bb') . $error);
        }
    }

    protected function save_payment_token($token, $user_id, $last4, $exp_date, $brand) {
        $card_types = [
            '1' => 'isracard',
            '2' => 'visa',
            '3' => 'diners',
            '4' => 'american express',
            '6' => 'mastercard',
        ];

        // exp_date format: MMYY
        $exp_month = strlen($exp_date) >= 4 ? substr($exp_date, 0, 2) : '';
        $exp_year  = strlen($exp_date) >= 4 ? '20' . substr($exp_date, -2) : '';

        $existing = WC_Payment_Tokens::get_customer_tokens($user_id, $this->id);
        foreach ($existing as $t) {
            if ($t->get_last4() === $last4) {
                $t->set_token($token);
                $t->save();
                return;
            }
        }

        $wc_token = new WC_Payment_Token_CC();
        $wc_token->set_token($token);
        $wc_token->set_gateway_id($this->id);
        $wc_token->set_last4($last4);
        $wc_token->set_expiry_month($exp_month);
        $wc_token->set_expiry_year($exp_year);
        $wc_token->set_card_type($card_types[$brand] ?? 'credit');
        $wc_token->set_user_id($user_id);
        $wc_token->save();
    }
}
