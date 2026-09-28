<?php

use Illuminate\Support\Facades\Session;
use Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility;

final class TestModeVisibilityAccessTest extends PluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        Session::flush();
        TestModeVisibility::reset();
    }

    private function visitWithFlag(?string $sValue): void
    {
        $this->app['request']->query->replace($sValue === null ? [] : [TestModeVisibility::QUERY_FLAG => $sValue]);
        TestModeVisibility::rememberQueryFlag();
    }

    public function test_guest_without_flag_cannot_see_test_methods(): void
    {
        $this->visitWithFlag(null);

        $this->assertFalse(TestModeVisibility::canSeeTestMethods());
    }

    public function test_flag_one_grants_visibility_for_later_requests(): void
    {
        $this->visitWithFlag('1');
        $this->visitWithFlag(null);

        $this->assertTrue(TestModeVisibility::canSeeTestMethods());
    }

    public function test_flag_zero_revokes_visibility(): void
    {
        $this->visitWithFlag('1');
        $this->visitWithFlag('0');

        $this->assertFalse(TestModeVisibility::canSeeTestMethods());
    }

    public function test_other_values_leave_the_session_untouched(): void
    {
        $this->visitWithFlag('yes');
        $this->assertFalse(TestModeVisibility::canSeeTestMethods());

        $this->visitWithFlag('1');
        $this->visitWithFlag('true');
        $this->assertTrue(TestModeVisibility::canSeeTestMethods());
    }
}
