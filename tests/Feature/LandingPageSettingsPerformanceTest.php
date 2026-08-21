<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\LandingPageController;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LandingPageSettingsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_landing_settings_are_loaded_in_one_query(): void
    {
        SystemSetting::set('landing.hero_title', 'A faster landing page', false, 'landing');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $settings = LandingPageController::getPublicSettings();

        $settingsQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'system_settings'));

        $this->assertCount(1, $settingsQueries);
        $this->assertSame('A faster landing page', $settings['landing.hero_title']);
        $this->assertSame('Log in', $settings['landing.signin_label']);
        $this->assertArrayNotHasKey('landing.page_enabled', $settings);
    }
}
