<?php

namespace App\Services;

use App\Models\SystemSetting;

class PusherPublicConfig
{
    public const WIDGET_CDN_URL = 'https://js.pusher.com/8.5.0/pusher.min.js';

    /**
     * Public config for authenticated app/Echo pages.
     *
     * @return array{key:string,cluster:string,enabled:bool}
     */
    public function app(): array
    {
        try {
            $key = SystemSetting::get('pusher_app_key')
                ?: config('broadcasting.connections.pusher.key')
                ?: env('PUSHER_APP_KEY', '');
            $cluster = SystemSetting::get('pusher_app_cluster')
                ?: config('broadcasting.connections.pusher.options.cluster')
                ?: env('PUSHER_APP_CLUSTER', 'mt1');
            $dbFlag = SystemSetting::get('pusher_enabled');

            return [
                'key' => (string) $key,
                'cluster' => (string) ($cluster ?: 'mt1'),
                'enabled' => $dbFlag === 'false' ? false : filled($key),
            ];
        } catch (\Throwable) {
            $key = config('broadcasting.connections.pusher.key') ?: env('PUSHER_APP_KEY', '');

            return [
                'key' => (string) $key,
                'cluster' => (string) (config('broadcasting.connections.pusher.options.cluster') ?: env('PUSHER_APP_CLUSTER', 'mt1')),
                'enabled' => filled($key),
            ];
        }
    }

    /**
     * Public config for the anonymous website widget.
     *
     * @return array{key:string,cluster:string,auth_endpoint:string,cdn_url:string}
     */
    public function widget(): array
    {
        $app = $this->app();

        return [
            'key' => $app['key'],
            'cluster' => $app['cluster'] ?: 'mt1',
            'auth_endpoint' => url('/widget/v1/broadcasting/auth'),
            'cdn_url' => self::WIDGET_CDN_URL,
        ];
    }
}
