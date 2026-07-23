<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemBrandingSeeder extends Seeder
{
    public function run(): void
    {
        SystemSetting::set('app_name', 'Cerqle', false, 'general');
        SystemSetting::set('app_tagline', 'Customer messaging on WhatsApp', false, 'general');
        SystemSetting::set('primary_color', '#8F5FA7', false, 'general');

        // Use the bundled Cerqle logo/favicon fallbacks instead of carrying over
        // uploaded ancestor-project assets.
        SystemSetting::whereIn('key', [
            'app_logo_path',
            'app_logo_disk',
            'app_favicon_path',
            'app_favicon_disk',
        ])->delete();
    }
}
