<?php

use App\Modules\Inbox\Http\Controllers\Widget\ChatWidgetEmbedController;
use App\Modules\Inbox\Http\Controllers\Widget\ChatWidgetPublicController;
use Illuminate\Support\Facades\Route;

/*
 * Public website live-chat widget surface. Loaded by InboxServiceProvider
 * OUTSIDE the `web` middleware group, so there is no session/CSRF — these are
 * anonymous cross-origin calls from the client's own website. Security is by
 * widget_key + per-widget domain whitelist + signed visitor session token +
 * rate limiting. CORS is granted via config/cors.php (widget/v1/*, widgets/chat/*).
 */

// One-line embed script the client drops on their site.
Route::get('/widgets/chat/{key}.js', [ChatWidgetEmbedController::class, 'script'])
    ->name('chat-widget.embed');

// Visitor API (JSON, polling & realtime).
Route::prefix('widget/v1')->name('widget.')->group(function () {
    Route::post('/session', [ChatWidgetPublicController::class, 'session'])
        ->middleware('throttle:30,1')->name('session');
    Route::post('/messages', [ChatWidgetPublicController::class, 'send'])
        ->middleware('throttle:60,1')->name('send');
    Route::post('/handover', [ChatWidgetPublicController::class, 'handover'])
        ->middleware('throttle:10,1')->name('handover');
    Route::post('/handoff', [ChatWidgetPublicController::class, 'handoff'])
        ->middleware('throttle:10,1')->name('handoff');
    Route::get('/messages', [ChatWidgetPublicController::class, 'poll'])
        ->middleware('throttle:300,1')->name('poll');
    Route::post('/broadcasting/auth', [ChatWidgetPublicController::class, 'broadcastingAuth'])
        ->middleware('throttle:300,1')->name('broadcasting-auth');
    Route::post('/typing', [ChatWidgetPublicController::class, 'typing'])
        ->middleware('throttle:60,1')->name('typing');
    Route::post('/push-subscriptions', [ChatWidgetPublicController::class, 'storePushSubscription'])
        ->middleware('throttle:60,1')->name('push-subscriptions');
});
