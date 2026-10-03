<?php

use Logingrupa\PayseraShopaholic\Classes\Collection\VisiblePaymentMethodCollection;
use Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection;

/**
 * Lovata builds collections through the container, so the binding is what hides test-mode
 * methods from every caller, the checkout list and the retry payment page alike.
 */
final class VisibleCollectionBindingTest extends PluginTestCase
{
    protected $autoMigrate = false;

    public function test_lovata_payment_method_collection_resolves_to_the_visible_one(): void
    {
        $this->assertInstanceOf(VisiblePaymentMethodCollection::class, PaymentMethodCollection::make());
    }

    public function test_the_visible_collection_still_resolves_itself(): void
    {
        $this->assertInstanceOf(VisiblePaymentMethodCollection::class, VisiblePaymentMethodCollection::make());
    }
}
