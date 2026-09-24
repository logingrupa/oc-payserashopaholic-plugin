# Paysera for Shopaholic

Two Paysera payment gateways for [Lovata OrdersShopaholic](https://shopaholic.one/) on October CMS v4, built for the Latvian and Lithuanian shops, where Paysera covers bank links and cards.

- `Paysera Checkout` (v2 line): Paysera's current API. OAuth2 client credentials, an order plus a payment link, the hosted Paysera payment page, an HMAC signed JSON webhook. Use this for new setups.
- `Paysera Classic (WebToPay)` (v1 line): the legacy project ID plus sign password API. Kept for shops whose Paysera project has not been moved to Checkout yet.

In both, only the server-to-server callback marks the order paid. The success URL just brings the customer back to the order page.

## Paysera Checkout (v2)

### Credentials

Paysera merchant portal, Checkout, your project, Integrations, API credentials: Client ID and Client Secret. Credentials are per project. A Classic project is not automatically a Checkout project; Paysera support copies it over on request. Test payments are a per-project toggle in the same portal (Enable Test Mode), nothing changes in the shop.

### Setup

1. Settings > Catalog configuration > Payment methods, pick `Paysera Checkout` as the gateway.
2. Fill Client ID, Client Secret, payer country, gateway currency, and the before, success and cancel statuses.
3. The webhook URL shown on the form (`/paysera/checkout/webhook`) is sent as the callback URL of every order. It must be reachable over HTTPS.

### Test mode switch

With "Test mode" on, the payment method is listed at checkout only for visitors who are logged into the October backend in the same browser. Customers do not see it. That lets you configure the method on the live shop, place a test order and let Paysera review the integration before the option goes public. The same rule applies to the Classic gateway's test mode. Implemented by registering this plugin's `PaymentMethodList` component under the OrdersShopaholic alias; theme templates need no change. Paysera's own test payments are a separate per-project toggle in the Paysera portal.

### Behaviour

- Purchase creates a Paysera order (reference = shop order id, amount in cents, success, failure, cancel and callback URLs) and then a payment link (one hour lifetime, hosted method picker, site language, order number as purpose, payer name and email). The customer is redirected to the link's payment URL. Paysera order id, link id, test flag, amount and currency are stored on the order.
- Order creation has no idempotency key, so the purchase runs once per shop order. A failed API call leaves the order unpaid with the Paysera error in the checkout message and the log.
- The access token is cached for its lifetime minus five minutes, one retry with a fresh token on 401.
- The webhook is verified with HMAC-SHA256 over the raw body keyed by the client secret. Order is found by `merchant_order_id`, the Paysera order id must match the stored one, the callback id is remembered so a redelivery changes nothing. Only the `order` `amount_paid_updated` event with status `paid`, amount paid at least the requested amount and the same currency marks the order paid, once. Other events are stored and answered with 200.
- Responses: 200 for anything verified, 401 for a bad signature (Paysera stops retrying), 400 for an unreadable body, 404 for an unknown order.

## Paysera Classic (v1)

The customer is sent to `https://bank.paysera.com/pay/` with a signed request. Paysera calls back to the shop after the payment and only that callback marks the order paid.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1+ with ext-openssl |
| October CMS | 4.x |
| Lovata.Toolbox, Lovata.Shopaholic, Lovata.OrdersShopaholic | current |
| Paysera project | project ID and sign password from the Paysera dashboard |

No Omnipay package and no Paysera SDK is needed. The Checkout client uses the Laravel HTTP client, the Classic request builder and callback validator live in `classes/api`.

## Installation

Add the plugin to the root `composer.json` like the other logingrupa plugins and run `composer update logingrupa/oc-payserashopaholic-plugin`, then `php artisan october:migrate`. There are no database migrations.

### Setup

1. Settings > Catalog configuration > Payment methods, open or create a method and pick `Paysera Classic (WebToPay)` as the payment gateway.
2. Fill the Gateway tab: project ID, sign password, test mode, payer country, gateway currency (`EUR`), and the before, success and cancel order statuses.
3. Keep "Send request to payment gateway when creating order" on.
4. The callback URL shown on the form (`/paysera/callback`) is sent with every request. Paysera needs no project-side setting, but the shop must be reachable from the internet, so it cannot be tested from a local machine without a tunnel.

### Behaviour

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

The unit tests cover the Classic request encoding, the SS1, SS2 and SS3 signature checks and the encrypted mode with a throwaway RSA pair from `tests/fixtures`, plus the Checkout webhook signature. The feature tests run the Checkout client against faked Paysera endpoints (token caching, 401 refresh, error mapping).

## Spec references

Checkout (v2) checked 2026-09-16 against https://developers.paysera.com/guides/checkout-modern (authentication, payment orders, payment links, webhooks, statuses, test mode), the source of `paysera/lib-checkout-integration-sdk` 3.2.1 and Paysera's WooCommerce "Smart Checkout" 1.5.0 and PrestaShop `payserapayments` 1.3.3 plugins. Checkout Modern is open to all Paysera merchants in Lithuania, Latvia and Estonia since 2026-08-08.

Classic (v1) checked 2026-09-15 against https://developers.paysera.com/guides/checkout-classic (request parameters, custom integration, processing callback pages), the source of `webtopay/libwebtopay` 3.1.6, Paysera's own WooCommerce plugin 3.12 and checkout SDK facade, the Laravel packages starlabspro/laravel-paysera and asd-lt/laravel-paysera, the Omnipay driver, and the TypeScript, Ruby, Python and .NET SDKs. The docs' callback sample treats status 3 as paid; every Paysera plugin and the facade pay on status 1 only, and this plugin follows the plugins. Checkout Classic is Paysera's legacy API, still served and documented with no sunset date. Paysera points new LV, LT and EE integrations to Checkout Modern (OAuth2 plus webhooks).
