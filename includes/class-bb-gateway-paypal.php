<?php
defined('ABSPATH') || exit;

class BB_Gateway_PayPal extends BB_Gateway_Base {

    public function __construct() {
        $this->id                 = 'bb_paypal';
        $this->method_title       = __('BB PayPal', 'woocommerce-bb');
        $this->method_description = __('PayPal payment via external_payments service.', 'woocommerce-bb');
        $this->has_fields         = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_setting('title', 'PayPal');
        $this->description = $this->get_setting('description', '');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_payment_settings']);
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

    public function process_payment_settings() {
        $this->process_admin_options();
    }

    public function process_payment($order_id) {
        $order    = wc_get_order($order_id);
        $user_key = $this->generate_user_key();
        $this->save_user_key($order, $user_key);

        $payload = $this->build_payload($order, $user_key, 'paypal');

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
}
