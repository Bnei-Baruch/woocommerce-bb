# BB Payment Gateways for WooCommerce

WooCommerce payment plugin with two gateways — Pelecard EMV (credit card) and PayPal — both routed through the [external_payments](https://github.com/Bnei-Baruch/external_payments) service.

## How It Works

1. Customer selects a payment method at checkout.
2. Plugin POSTs order data to `external_payments` (`/emv/new` or `/paypal/new`), including WooCommerce return URLs.
3. `external_payments` stores the return URLs, creates the payment session with Pelecard/PayPal, and returns a redirect URL.
4. Customer is redirected to Pelecard terminal or PayPal approval page.
5. After payment, Pelecard/PayPal redirects back to `external_payments`, which updates its DB and redirects the browser to the WooCommerce return URL.
6. Plugin's return handler (`handle_return`) marks the WooCommerce order as paid/failed/cancelled and shows the appropriate page.

## Installation

1. Upload the `woocommerce-bb` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to **WooCommerce → Settings → Payments** and configure each gateway.

## Configuration

Both gateways share these settings:

| Setting     | Description                                                                               |
|-------------|-------------------------------------------------------------------------------------------|
| API URL     | Base URL of the `external_payments` service, e.g. `https://checkout.kbb1.com`            |
| Organization| Organization code (`ben2` or `meshp18`)                                                   |
| Default SKU | Fallback SKU sent to Priority ERP when the product has no SKU set. **Required.**          |

The SKU is taken from the WooCommerce product's own SKU field. The **Default SKU** is only used when the product has no SKU set. Saving the settings with any of these fields empty will show a validation error.

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- Running [external_payments](https://github.com/Bnei-Baruch/external_payments) service

## Return URL Parameters

`external_payments` appends these query parameters to the `GoodURL` on success:

- `/emv/good`: `?success=1&ApprovalNo=XXXX`
- `/paypal/good`: `?success=1&transaction_id=XXXX&order_id=YYYY`

The plugin reads `transaction_id` or `ApprovalNo` as the WooCommerce transaction ID.
