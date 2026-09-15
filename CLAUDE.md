# Logingrupa.PayseraShopaholic

Paysera (WebToPay) gateway for Lovata OrdersShopaholic. Namespace Logingrupa\PayseraShopaholic,
composer package logingrupa/oc-payserashopaholic-plugin. Runs on the .lv and .lt shops.
README.md documents setup and the callback contract.

## Environment

- Parent app: C:\laragon\www\nc.
- This plugin dir is its OWN git repo - commit here, not in the root repo.

## Architecture map

- classes/api/     PayseraRequest (data encoding, SS1 sign, redirect URL),
                   PayseraCallback (decode, SS1 + SS3/SS2 verify, AES-GCM encrypted mode,
                   public key cache), PayseraCallbackException (HTTP code in getCode())
- classes/helper/  PayseraPaymentGateway (AbstractPaymentGateway: purchase + processCallback)
- classes/event/   ExtendFieldHandler (Gateway tab fields), PaymentMethodModelHandler
                   (gateway list, gateway class, validation rules)
- routes.php       GET|POST /paysera/callback, CSRF excluded on purpose (signed instead)
- partials/        callback URL hint on the payment method form
- tests/Unit       pure PHPUnit, no DB; tests/fixtures holds a throwaway RSA pair

## Quality gates

`vendor/bin/phpunit -c plugins/logingrupa/payserashopaholic/phpunit.xml` from the nc root.
Root `composer lint` does not cover plugins/logingrupa; lint by hand.

## Ship

Ship via /nc-ship (root CLAUDE.md release flow). Verify on .lv and .lt; .no does not use Paysera.

## Conventions

Root CLAUDE.md governs: Hungarian notation, Tiger-Style, no jQuery.

## Gotchas

- Order status changes only from the callback. Never set success on the accept URL, Paysera
  documents accepturl as untrusted.
- Status 0 is "not executed yet", not a cancel: a later status 1 for the same order is normal.
  Only status 1 approves (the docs sample also approves on 3, no Paysera plugin does, so
  neither do we). 4 and 5 log a warning. Do not add a cancel path on 0.
- Idempotency is the paid_at marker in payment_response, not a status compare: a retried
  callback after the order moved to shipped must not fire setSuccessStatus again.
- Amount rule: amount/currency OR payamount/paycurrency must equal the stored request
  (Paysera checkout SDK facade isMerchantOrderPaid). Preferring payamount alone rejected
  currency-converted payments.
- Paysera's public.key is an X.509 certificate, openssl_pkey_get_public accepts it. Expires
  2027-02-05; the refetch-on-failure path covers the rotation.
- Spec validated 2026-09-15 against developers.paysera.com/guides/checkout-classic and
  lib-webtopay 3.1.6 (three parallel validators). Re-check there, the old
  /en/checkout/integrations/... spec URLs are gone (404).
- The callback route trusts nothing before the signature check except the orderid needed to
  find the password. Keep that order: decode, load order, verify, then act.
- Amount check compares against payment_data['request'] stored at purchase time, not the
  live order total, so a backend edit after checkout cannot desync it.
- The local nc DB payment method id 4 carried a v1-key encrypted gateway_property
  ("The MAC is invalid"); saving new credentials overwrites it.
- Paysera needs a public callback URL. Local testing uses signed callbacks built by
  .tools/paysera-probe/smoke-purchase.php in the nc root.
