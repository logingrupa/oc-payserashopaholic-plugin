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
  Statuses 1 and 3 approve, 4 needs a manual funds check. Do not add a cancel path on 0.
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
