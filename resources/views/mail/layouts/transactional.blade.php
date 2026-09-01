<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
    <style>
        @media only screen and (max-width: 640px) {
            .email-container { width: 100% !important; }
            .email-card { padding: 30px 24px !important; }
        }
    </style>
</head>
<body style="background-color:#f6f5f7;color:#3f3f46;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;margin:0;padding:0;-webkit-text-size-adjust:100%;width:100%;">
<div style="display:none;font-size:1px;color:#f6f5f7;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{{ $subject }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f6f5f7;width:100%;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table class="email-container" role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:600px;">
                <tr>
                    <td style="padding:0 4px 20px;text-align:left;">
                        @if($appUrl)
                            <a href="{{ $appUrl }}" style="color:#43274d;font-size:22px;font-weight:700;letter-spacing:3px;text-decoration:none;">CERQLE</a>
                        @else
                            <span style="color:#43274d;font-size:22px;font-weight:700;letter-spacing:3px;">CERQLE</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="email-card" style="background-color:#ffffff;border:1px solid #e7e3e9;border-top:4px solid #7c3f91;border-radius:10px;padding:38px 40px;">
                        <h1 style="color:#18181b;font-size:24px;font-weight:700;letter-spacing:-0.3px;line-height:32px;margin:0 0 22px;">{{ $subject }}</h1>
                        <div style="color:#52525b;font-size:15px;line-height:24px;">
                            {!! $content !!}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="color:#8a8490;font-size:12px;line-height:18px;padding:20px 8px 0;text-align:center;">
                        This is an automated service email from {{ $appName }}.<br>
                        @if($appUrl)
                            <a href="{{ $appUrl }}" style="color:#6d6471;text-decoration:underline;">{{ preg_replace('#^https?://#', '', rtrim($appUrl, '/')) }}</a>
                            <span aria-hidden="true"> &middot; </span>
                            <a href="{{ rtrim($appUrl, '/') }}/privacy" style="color:#6d6471;text-decoration:underline;">Privacy</a>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
