<?php

use PHPUnit\Framework\TestCase;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraCheckoutPaymentGateway;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway;
use Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility;

final class TestModeVisibilityTest extends TestCase
{
    public function test_checkout_method_is_test_when_its_flag_is_on(): void
    {
        $this->assertTrue(TestModeVisibility::isTestMethod(PayseraCheckoutPaymentGateway::CODE, ['checkout_test_mode' => 1]));
        $this->assertTrue(TestModeVisibility::isTestMethod(PayseraCheckoutPaymentGateway::CODE, ['checkout_test_mode' => '1']));
        $this->assertFalse(TestModeVisibility::isTestMethod(PayseraCheckoutPaymentGateway::CODE, ['checkout_test_mode' => 0]));
        $this->assertFalse(TestModeVisibility::isTestMethod(PayseraCheckoutPaymentGateway::CODE, []));
    }

    public function test_classic_method_uses_its_own_flag(): void
    {
        $this->assertTrue(TestModeVisibility::isTestMethod(PayseraPaymentGateway::CODE, ['test_mode' => true]));
        $this->assertFalse(TestModeVisibility::isTestMethod(PayseraPaymentGateway::CODE, ['checkout_test_mode' => true]));
    }

    public function test_other_gateways_are_never_hidden(): void
    {
        $this->assertFalse(TestModeVisibility::isTestMethod('PayPal_Express', ['test_mode' => true]));
        $this->assertFalse(TestModeVisibility::isTestMethod('', ['test_mode' => true]));
    }
}
