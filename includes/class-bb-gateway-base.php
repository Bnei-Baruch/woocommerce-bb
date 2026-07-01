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

    protected function sku() {
        return $this->get_setting('sku', 'WOO');
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

    protected function build_payload(WC_Order $order, $user_key, $endpoint) {
        $base = $this->base_url();
        return [
            'UserKey'      => $user_key,
            'GoodURL'      => $base . '/' . $endpoint . '/good?UserKey=' . urlencode($user_key),
            'ErrorURL'     => $base . '/' . $endpoint . '/error?UserKey=' . urlencode($user_key),
            'CancelURL'    => $base . '/' . $endpoint . '/cancel?UserKey=' . urlencode($user_key),
            'Name'         => $order->get_formatted_billing_full_name(),
            'Price'        => (float) $order->get_total(),
            'Currency'     => $this->map_currency(get_woocommerce_currency()),
            'Email'        => $order->get_billing_email(),
            'Phone'        => $this->order_phone($order),
            'Street'       => $order->get_billing_address_1(),
            'City'         => $order->get_billing_city(),
            'Country'      => $order->get_billing_country(),
            'Details'      => sprintf(__('Order #%s', 'woocommerce-bb'), $order->get_order_number()),
            'SKU'          => $this->sku(),
            'VAT'          => 'n',
            'Installments' => 1,
            'Language'     => $this->map_language(),
            'Reference'    => (string) $order->get_order_number(),
            'Organization' => $this->org(),
            'IsVisual'     => false,
        ];
    }

    protected function post_to_gateway($url, $payload) {
        $response = wp_remote_post($url, [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => wp_json_encode($payload),
            'timeout'     => 30,
            'sslverify'   => true,
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

    // Shared admin fields (api_url, organization, sku) — merged by subclasses.
    protected function shared_form_fields() {
        return [
            'api_url' => [
                'title'       => __('API URL', 'woocommerce-bb'),
                'type'        => 'text',
                'description' => __('Base URL of the external_payments service (no trailing slash).', 'woocommerce-bb'),
                'default'     => 'https://checkout.kbb1.com',
            ],
            'organization' => [
                'title'   => __('Organization', 'woocommerce-bb'),
                'type'    => 'text',
                'default' => 'ben2',
            ],
            'sku' => [
                'title'   => __('SKU', 'woocommerce-bb'),
                'type'    => 'text',
                'default' => 'WOO',
            ],
        ];
    }

    // Store user_key on order so Good/Error/Cancel callbacks can retrieve the order.
    protected function save_user_key(WC_Order $order, $user_key) {
        $order->update_meta_data('_bb_user_key', $user_key);
        $order->save();
    }
}
