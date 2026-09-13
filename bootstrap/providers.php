<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastChannelsServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\NotificationDeliveryServiceProvider;
use App\Providers\PusherSettingsServiceProvider;

return [
    AppServiceProvider::class,
    NotificationDeliveryServiceProvider::class,
    ModuleServiceProvider::class,
    PusherSettingsServiceProvider::class,
    BroadcastChannelsServiceProvider::class,
];
