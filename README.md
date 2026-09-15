# Paysera for Shopaholic

Paysera (WebToPay) payment gateway for [Lovata OrdersShopaholic](https://shopaholic.one/) on October CMS v4. Built for the Latvian and Lithuanian shops, where Paysera covers bank links and cards.

The customer is sent to `https://bank.paysera.com/pay/` with a signed request. Paysera calls back to the shop after the payment and only that callback marks the order paid. The accept URL just brings the customer back to the order page.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1+ with ext-openssl |
| October CMS | 4.x |
| Lovata.Toolbox, Lovata.Shopaholic, Lovata.OrdersShopaholic | current |
| Paysera project | project ID and sign password from the Paysera dashboard |

No Omnipay package is needed. The request builder and callback validator live in `classes/api`.

## Installation

Add the plugin to the root `composer.json` like the other logingrupa plugins and run `composer update logingrupa/oc-payserashopaholic-plugin`, then `php artisan october:migrate`. There are no database migrations.

## Setup

1. Settings > Catalog configuration > Payment methods, open or create a method and pick `Paysera` as the payment gateway.
2. Fill the Gateway tab: project ID, sign password, test mode, payer country, gateway currency (`EUR`), and the before, success and cancel order statuses.
3. Keep "Send request to payment gateway when creating order" on.
4. The callback URL shown on the form (`/paysera/callback`) is sent with every request. Paysera needs no project-side setting, but the shop must be reachable from the internet, so it cannot be tested from a local machine without a tunnel.

## Behaviour

- Amount is sent in cents, currency from the payment method, language from the site locale (lv, lt, ru, en), customer name and email from the order properties.
- Accept and cancel URLs come from the `shopaholic.payment_method.omnipay.gateway.return_url` and `cancel_url` events, so the existing storeextender and retrypayment listeners send the customer to the order page.
- The callback verifies the SS1 signature (md5 of data and password) and, when Paysera sends it, the SS2 RSA signature against the Paysera public key (cached one day). Then it checks project ID, callback type `macro`, the test flag against the method's test mode, and amount plus currency against the stored request. Status `1` sets the success status, status `0` the cancel status, anything else is logged. The response body is `OK`.
- A replayed callback for an already paid order answers `OK` without changing anything.
- Rejections answer 400, unknown orders 404, bad signatures 403. All of them are logged.

## Tests

```
vendor/bin/phpunit -c plugins/logingrupa/payserashopaholic/phpunit.xml
```

Ten unit tests cover the request encoding and the callback signature checks with a throwaway RSA pair from `tests/fixtures`.
