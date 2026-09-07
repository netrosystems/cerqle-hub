<?php

namespace Tests\Unit;

use App\Models\Plan;
use PHPUnit\Framework\TestCase;

class PaidLauncherEntitlementTest extends TestCase
{
    public function test_launcher_access_is_price_based_not_name_or_white_label_flag(): void
    {
        foreach (['Standard', 'Pro', 'Enterprise', 'Custom'] as $name) {
            $plan = new Plan(['name' => $name, 'monthly_price_cents' => 2000, 'white_label_enabled' => false]);
            $this->assertTrue($plan->hasFeature('custom_launcher_icon'));
        }
        $this->assertTrue((new Plan(['monthly_price_cents' => 0, 'yearly_price_cents' => 20000]))->hasFeature('custom_launcher_icon'));
        $this->assertTrue((new Plan(['price_cents' => 2000]))->hasFeature('custom_launcher_icon'));
        $this->assertFalse((new Plan(['name' => 'Pro', 'price_cents' => 0, 'white_label_enabled' => true]))->hasFeature('custom_launcher_icon'));
        $this->assertFalse((new Plan)->hasFeature('custom_launcher_icon'));
    }
}
