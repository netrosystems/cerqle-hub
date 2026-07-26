<?php

namespace App\Modules\Inbox\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Models\ChatWidget;
use Illuminate\Http\Response;

/**
 * Public JS embed endpoint — the single line a client drops on their website:
 *   <script src="https://<host>/widgets/chat/<key>.js" async></script>
 *
 * Returns a tiny per-widget bootstrap that injects the widget's config onto
 * window and loads the shared, cacheable widget bundle (public/widget/cerqle-
 * chat-widget.js). The heavy UI lives in the static bundle, not here.
 */
class ChatWidgetEmbedController extends Controller
{
    /** Bump when the static widget bundle changes, to bust the browser cache. */
    private const BUNDLE_VERSION = '17';

    public function script(string $key): Response
    {
        $widget = ChatWidget::where('widget_key', $key)->where('enabled', true)->firstOrFail();

        $apiBase = rtrim(url('/'), '/');
        $bundleUrl = $apiBase.'/widget/cerqle-chat-widget.js?v='.self::BUNDLE_VERSION;
        $configJson = json_encode(array_merge($widget->publicConfig(), ['api_base' => $apiBase]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $keyJson = json_encode($key);
        $bundleJson = json_encode($bundleUrl);

        // Optional domain guard so the widget never even loads off an unlisted site
        // (the API enforces this again server-side).
        $domainCheck = '';
        if (! empty($widget->allowed_domains)) {
            $domainsJson = json_encode(array_values($widget->allowed_domains));
            $domainCheck = <<<JS

  try {
    var _allowed = {$domainsJson};
    var _host = (window.location.hostname || '').toLowerCase().replace(/^www\\./, '');
    var _ok = false;
    for (var _i = 0; _i < _allowed.length; _i++) {
      var _d = (_allowed[_i] || '').toLowerCase().replace(/^https?:\\/\\//, '').replace(/^www\\./, '').split('/')[0];
      if (_d && (_host === _d || _host.slice(-(_d.length + 1)) === '.' + _d)) { _ok = true; break; }
    }
    if (!_ok) return;
  } catch (e) {}

JS;
        }

        $js = <<<JS
/* Cerqle Chat Widget loader */
(function () {
  'use strict';
  if (window.__WB_CHAT_LOADED__) return;
  window.__WB_CHAT_LOADED__ = true;
{$domainCheck}
  window.__WB_CHAT__ = { key: {$keyJson}, config: {$configJson} };
  var s = document.createElement('script');
  s.src = {$bundleJson};
  s.async = true;
  s.defer = true;
  (document.head || document.documentElement).appendChild(s);
})();
JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
