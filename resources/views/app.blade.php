<!DOCTYPE html>
@php
    $locale = request()->attributes->get('active_locale', app()->getLocale());
    $htmlDir = request()->attributes->get('is_rtl', false) ? 'rtl' : 'ltr';
@endphp
@php
    // Guarded: the database may be unavailable during first-run install.
    try {
        $serverTheme = auth()->user()?->theme ?? null;
    } catch (\Throwable) {
        $serverTheme = null;
    }
@endphp
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $htmlDir }}">
    <head>
        <script>
            (function() {
                var server = @json($serverTheme);
                var stored = localStorage.getItem('theme');
                var pref = (stored === 'light' || stored === 'dark') ? stored : ((server === 'light' || server === 'dark') ? server : 'light');
                document.documentElement.classList.toggle('dark', pref === 'dark');
            })();
        </script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="vapid-public-key" content="{{ config('webpush.vapid_public_key') }}">

        @php
            $isInstallRoute = request()->is('install', 'install/*');
            $documentTitle = $isInstallRoute ? 'Cerqle' : config('app.name', 'Cerqle');
            try {
                $faviconPath = $isInstallRoute ? null : \App\Models\SystemSetting::get('app_favicon_path');
                // Honor the disk the favicon was actually saved to (may be a cloud
                // provider). Hardcoding 'public' produced a wrong/404 URL whenever
                // the active storage disk was not the local public one.
                $faviconDisk = $isInstallRoute ? 'public' : \App\Models\SystemSetting::get('app_favicon_disk', 'public');
                if ($faviconPath) {
                    app(\App\Services\StorageManager::class)->ensureDiskReady($faviconDisk);
                    $faviconUrl = \Illuminate\Support\Facades\Storage::disk($faviconDisk)->url($faviconPath);
                } else {
                    $faviconUrl = null;
                }
            } catch (\Throwable) {
                $faviconUrl = null;
            }
        @endphp
        <title inertia>{{ $documentTitle }}</title>
        @if($faviconUrl)
            <link rel="icon" href="{{ $faviconUrl }}">
            <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
        @else
            {{-- Fallback brand icon for browser tabs and mobile home screens. --}}
            <link rel="icon" type="image/svg+xml" href="/cerqle-icon.svg">
            <link rel="apple-touch-icon" href="/cerqle-icon.svg">
        @endif

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=poppins:400,500,600,700,800&display=swap" rel="stylesheet" />
        {{-- Anek Bangla for Bengali script. The Bengali glyph files are lazy-loaded
             by unicode-range, so they're only fetched when bn text actually renders
             (i.e. html[lang="bn"]); keeping the link unconditional means a client-side
             locale switch picks it up without a full page reload. --}}
        <link href="https://fonts.bunny.net/css?family=anek-bangla:400,500,600,700&display=swap" rel="stylesheet" />

        @php($oneSignalAppId = app(\App\Services\OneSignalService::class)->publicAppId())
        @php($oneSignalExternalId = auth('web')->check() ? 'user:'.auth('web')->id() : null)
        @if($oneSignalAppId)
        <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
        <script>
            window.OneSignalDeferred = window.OneSignalDeferred || [];

            OneSignalDeferred.push(async function (OneSignal) {
                var _keepPushAfterLogoutKey = 'cerqle_keep_push_after_logout';

                try {
                    await OneSignal.init({
                        appId: "{{ $oneSignalAppId }}",
                        notifyButton: { enable: false },
                        allowLocalhostAsSecureOrigin: {{ app()->environment('local') ? 'true' : 'false' }},
                    });
                } catch (e) {
                    console.warn('[onesignal] init failed — push notifications disabled:', e?.message ?? e);
                    return;
                }

                // If permission is granted but the subscription has no token, the local
                // OneSignal state is stale (leftover from a previous broken registration).
                // Opt-out and back in to force a fresh SW subscription.
                if (Notification.permission === 'granted') {
                    try {
                        var sub = OneSignal.User?.PushSubscription;
                        if (sub && !sub.token && sub.optedIn) {
                            await sub.optOut();
                            await sub.optIn();
                        }
                    } catch (_) {}
                }

                // Suppress push notification when the user is actively viewing the inbox
                // (Echo already shows the message in real-time there).
                // On every other page the notification is shown as normal.
                try {
                    OneSignal.Notifications.addEventListener('foregroundWillDisplay', function(event) {
                        var p = window.location.pathname;
                        if (p.includes('/inbox')) {
                            event.preventDefault(); // user can see the message live — no popup needed
                        }
                        // else: let OneSignal display the notification
                    });
                } catch(_) {}

                @if($oneSignalExternalId)
                // Only login once we have a real push subscription (non-empty token).
                // Calling login() with an empty token causes a 400 from OneSignal.
                var _osUserId = "{{ $oneSignalExternalId }}";

                async function osLogin() {
                    try {
                        var sub = OneSignal.User?.PushSubscription;
                        var token = sub?.token;
                        var subId  = sub?.id;
                        // A "local-" prefixed ID means the subscription hasn't been
                        // confirmed by OneSignal's server yet; calling login() in that
                        // state returns 400 "No aliases found".
                        if (!token || (subId && String(subId).startsWith('local-'))) return;
                        await OneSignal.login(_osUserId);
                    } catch (e) {
                        console.warn('[onesignal] login failed:', e?.message ?? e);
                    }
                }
                window.osLogin = osLogin;
                window.osKeepAfterLogoutEnabled = function () {
                    try { return localStorage.getItem(_keepPushAfterLogoutKey) === '1'; } catch (_) { return false; }
                };
                window.osSetKeepAfterLogout = async function (enabled) {
                    try {
                        if (enabled) localStorage.setItem(_keepPushAfterLogoutKey, '1');
                        else localStorage.removeItem(_keepPushAfterLogoutKey);
                    } catch (_) {}
                    if (enabled) await osLogin();
                };

                // If permission is already granted, wait for the subscription token
                // to be populated before attempting login.
                if (Notification.permission === 'granted') {
                    var token = OneSignal.User?.PushSubscription?.token;
                    if (token) {
                        osLogin();
                    } else {
                        // Token arrives asynchronously — wait for the subscription change event
                        try {
                            OneSignal.User.PushSubscription.addEventListener('change', function handler(e) {
                                var cur = e.current;
                                if (cur?.token && !(cur?.id && String(cur.id).startsWith('local-'))) {
                                    OneSignal.User.PushSubscription.removeEventListener('change', handler);
                                    osLogin();
                                }
                            });
                        } catch (_) {}
                    }
                }

                // Login when the user grants permission later (e.g. after our prompt).
                try {
                    OneSignal.Notifications.addEventListener('permissionChange', function (granted) {
                        if (granted) {
                            // Give the SW subscription a moment to generate a token
                            setTimeout(osLogin, 1000);
                        }
                    });
                } catch (_) {}
                try {
                    OneSignal.User.PushSubscription.addEventListener('change', function (event) {
                        var cur = event.current;
                        if (cur?.token && !(cur?.id && String(cur.id).startsWith('local-'))) {
                            osLogin();
                        }
                    });
                } catch (_) {}
                @else
                // Do not keep the former user's identifier in a shared browser.
                var _keepPushAfterLogout = false;
                try { _keepPushAfterLogout = localStorage.getItem(_keepPushAfterLogoutKey) === '1'; } catch (_) {}
                if (!_keepPushAfterLogout) {
                    try { await OneSignal.logout(); } catch (_) {}
                }
                @endif
            });

            // Suppress any unhandled SDK rejections so they don't pollute the console.
            window.addEventListener('unhandledrejection', function (ev) {
                var stack = String(ev.reason?.stack ?? ev.reason ?? '');
                if (stack.includes('OneSignal') || stack.includes('onesignal')) ev.preventDefault();
            });
        </script>
        @endif

        <!-- Facebook JS SDK — loaded eagerly when Meta App is configured -->
        <div id="fb-root"></div>
        {{-- Inline assignment survives Blade's compiled include scope and is safe
             while the integration table is unavailable during first-run setup. --}}
        @php($metaAppId = rescue(fn () => \App\Modules\Integrations\Services\CredentialResolver::system()->meta()?->appId(), null, false))
        @if($metaAppId)
        <script>
            window.fbAsyncInit = function() {
                FB.init({
                    appId: '{{ e($metaAppId) }}',
                    autoLogAppEvents: true,
                    xfbml: false,
                    version: 'v20.0',
                });
                window.__fbSdkReady = true;
            };
        </script>
        <script async defer crossorigin="anonymous"
            src="https://connect.facebook.net/en_US/sdk.js"></script>
        @endif

        {{-- Public Cerqle WhatsApp widget. Keep this off authenticated app,
             admin, and installer screens so it cannot create duplicate inboxes. --}}
        @if(isset($page['component']) && ($page['component'] === 'Welcome' || str_starts_with($page['component'], 'marketing/')))
        <script src="https://cerqle.ai/widgets/whatsapp/a4itR0GJcwiR8Zts0x0PmjnQoMif6lw8.js" async defer></script>
        @endif

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', 'resources/js/Pages/' . (isset($page['component']) ? $page['component'] : 'Dashboard') . '.jsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
