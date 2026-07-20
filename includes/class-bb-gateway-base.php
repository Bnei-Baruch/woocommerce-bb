<?php
defined('ABSPATH') || exit;

abstract class BB_Gateway_Base extends WC_Payment_Gateway {

    protected function get_setting($key, $default = '') {
        return $this->get_option($key, $default);
    }

    protected function base_url() {
        return untrailingslashit($this->get_setting('api_url'));
    }

    protected function org() {
        return $this->get_setting('organization');
    }

    protected function defaultSKU() {
        return $this->get_setting('sku', '');
    }

    protected function order_sku(WC_Order $order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $sku = $product->get_sku();
                if ($sku !== '') {
                    return $sku;
                }
            }
        }
        return $this->defaultSKU();
    }

    protected function generate_user_key() {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    protected function map_currency($currency) {
        $map = ['ILS' => 'ILS', 'USD' => 'USD', 'EUR' => 'EUR'];
        return $map[$currency] ?? 'ILS';
    }

    protected function map_language() {
        $locale = get_locale();
        if (strpos($locale, 'he_') === 0) return 'HE';
        if (strpos($locale, 'ru_') === 0) return 'RU';
        return 'EN';
    }

    protected function order_phone(WC_Order $order) {
        $phone = $order->get_billing_phone();
        return $phone ?: '0000000000';
    }

    // Return URL handled by this plugin (WC API endpoint).
    protected function return_url($order_id, $user_key, $action) {
        return add_query_arg([
            'action'   => $action,
            'order_id' => $order_id,
            'user_key' => $user_key,
        ], home_url('/?wc-api=' . $this->id . '_return'));
    }

    protected function order_has_category($order_id, $slug) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        $slug_lower = strtolower($slug);
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $lookup_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $terms = get_the_terms($lookup_id, 'product_cat');
            if (!$terms || is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (strtolower($term->slug) === $slug_lower || strtolower($term->name) === $slug_lower) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function build_payload(WC_Order $order, $user_key) {
        $is_donation = $this->order_has_category($order->get_id(), 'donation');
        return [
            'UserKey'      => $user_key,
            'GoodURL'      => $this->return_url($order->get_id(), $user_key, 'good'),
            'ErrorURL'     => $this->return_url($order->get_id(), $user_key, 'error'),
            'CancelURL'    => $this->return_url($order->get_id(), $user_key, 'cancel'),
            'Name'         => $order->get_formatted_billing_full_name(),
            'Price'        => (float) $order->get_total(),
            'Currency'     => $this->map_currency(get_woocommerce_currency()),
            'Email'        => $order->get_billing_email(),
            'Phone'        => $this->order_phone($order),
            'Street'       => $order->get_billing_address_1(),
            'City'         => $order->get_billing_city(),
            'Country'      => $order->get_billing_country(),
            'Details'      => sprintf(__('Order #%s', 'woocommerce-bb'), $order->get_order_number()),
            'SKU'          => $this->order_sku($order),
            'VAT'          => $is_donation ? 'Y' : 'N',
            'TaxType' => $is_donation ? (string) $order->get_meta('tax_customer_type') : '',
            'TaxId'   => $is_donation ? (string) $order->get_meta('tax_id_code') : '',
            'Installments' => 1,
            'Language'     => $this->map_language(),
            'Reference'    => $this->get_setting('reference_prefix') . $order->get_order_number(),
            'Organization' => $this->org(),
            'IsVisual'     => false,
        ];
    }

    protected function post_to_gateway($url, $payload) {
        $response = wp_remote_post($url, [
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode($payload),
            'timeout'   => 30,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['url'])) {
            $msg = $body['error'] ?? 'Unexpected response from payment gateway';
            throw new Exception($msg);
        }

        return $body['url'];
    }

    // Handle browser return from external_payments after payment outcome.
    // Registered via: add_action('woocommerce_api_{id}_return', [$this, 'handle_return'])
    public function handle_return() {
        $action   = sanitize_key($_GET['action'] ?? '');
        $order_id = absint($_GET['order_id'] ?? 0);
        // transaction_id appended by external_payments on good return (PayPal: transaction_id, EMV: ApprovalNo)
        $txn_id   = sanitize_text_field($_GET['transaction_id'] ?? $_GET['ApprovalNo'] ?? '');

        $order = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            wc_add_notice(__('Order not found.', 'woocommerce-bb'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        if ($action === 'good') {
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

        // error
        $order->update_status('failed', __('Payment failed.', 'woocommerce-bb'));
        wc_add_notice(__('Payment failed. Please try again.', 'woocommerce-bb'), 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    protected function shared_form_fields() {
        return [
            'api_url' => [
                'title'       => __('API URL', 'woocommerce-bb'),
                'type'        => 'text',
                'description' => __('Base URL of the external_payments service (no trailing slash).', 'woocommerce-bb'),
                'default'     => 'https://checkout.kbb1.com',
            ],
            'organization' => [
                'title'       => __('Organization', 'woocommerce-bb'),
                'type'        => 'text',
                'description' => __('Required. Organization code (e.g. ben2 or meshp18).', 'woocommerce-bb'),
                'default'     => 'ben2',
            ],
            'sku' => [
                'title'       => __('Default SKU', 'woocommerce-bb'),
                'type'        => 'text',
                'description' => __('Fallback SKU when product has no SKU set. Required.', 'woocommerce-bb'),
                'default'     => '',
            ],
            'reference_prefix' => [
                'title'       => __('Prefix for order reference', 'woocommerce-bb'),
                'type'        => 'text',
                'description' => __('Optional prefix prepended to the order number sent as ParamX (max 19 chars total).', 'woocommerce-bb'),
                'default'     => '',
            ],
        ];
    }

    public function process_admin_options() {
        $saved = parent::process_admin_options();

        $required = ['api_url' => __('API URL', 'woocommerce-bb'), 'organization' => __('Organization', 'woocommerce-bb'), 'sku' => __('Default SKU', 'woocommerce-bb')];
        foreach ($required as $key => $label) {
            if (trim($this->get_option($key)) === '') {
                WC_Admin_Settings::add_error(sprintf(__('%s is required for %s gateway.', 'woocommerce-bb'), $label, $this->method_title));
            }
        }

        return $saved;
    }

    protected function save_user_key(WC_Order $order, $user_key) {
        $order->update_meta_data('_bb_user_key', $user_key);
        $order->save();
    }
}
