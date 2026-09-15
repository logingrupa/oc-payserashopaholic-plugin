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
- The callback verifies the SS1 signature (md5 of data and password) and the strongest RSA signature Paysera sends, SS3 (SHA-256) before SS2 (SHA-1), against the Paysera public key. A callback without an RSA signature is refused, as in Paysera's library. The key (an X.509 certificate) is cached for a day and fetched again once when a check fails, so a key rotation does not block payments. Encrypted callbacks (no signature fields, AES-256-GCM with the project password) are decrypted with the password of each Paysera payment method. Then it checks project ID, callback type `macro`, the test flag against the method's test mode, and that either the `amount`/`currency` pair or the `payamount`/`paycurrency` pair equals the stored request, the rule from Paysera's checkout SDK facade.
- Only status `1` sets the success status, and only once: a `paid_at` marker is stored on the order, so a retried callback after the order moved on to shipped changes nothing. Status `0`, `2` and `3` are not a payment and change nothing. Status `4` (funds not confirmed by the bank) and `5` (refunded) are logged as warnings for a manual check. The response body is `OK`.
- An authentic callback that fails the business checks is stored on the order under `rejected_callback` with the reason.
- Rejections answer 400, unknown orders 404, bad signatures 403, and 503 while the Paysera public key cannot be downloaded so Paysera retries. All of them are logged.
- Request version is `1.8`, the value the current Checkout Classic docs use. The wire format (base64url data, md5 sign) has not changed since 1.6 and is what Paysera's own library 3.1.6 still sends.

## Tests

```
vendor/bin/phpunit -c plugins/logingrupa/payserashopaholic/phpunit.xml
```

The unit tests cover the request encoding, the SS1, SS2 and SS3 signature checks and the encrypted mode with a throwaway RSA pair from `tests/fixtures`.

## Spec references

Checked 2026-09-15 against https://developers.paysera.com/guides/checkout-classic (request parameters, custom integration, processing callback pages), the source of `webtopay/libwebtopay` 3.1.6, Paysera's own WooCommerce plugin 3.12 and checkout SDK facade, the Laravel packages starlabspro/laravel-paysera and asd-lt/laravel-paysera, the Omnipay driver, and the TypeScript, Ruby, Python and .NET SDKs. The docs' callback sample treats status 3 as paid; every Paysera plugin and the facade pay on status 1 only, and this plugin follows the plugins. Checkout Classic is Paysera's legacy API, still served and documented with no sunset date. Paysera points new LV, LT and EE integrations to Checkout Modern (OAuth2 plus webhooks).
