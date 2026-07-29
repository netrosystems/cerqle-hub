<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Services\Sms\AlarisSmsDriver;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SmsProviderController extends Controller
{
    public const PROVIDERS = ['twilio', 'nexmo', 'messagebird', 'smsbd', 'reve', 'alaris', 'bulksmsbd', 'sms_dot_bd', 'mimsms', 'fast2sms', 'amazon_sns'];

    /**
     * Providers currently offered in the client SMS Gateways screen.
     *
     * The remaining drivers stay registered so existing configurations and
     * delivery callbacks continue to work, but they are intentionally hidden
     * from new client setup: nexmo, messagebird, smsbd, reve, bulksmsbd,
     * sms_dot_bd, mimsms, and fast2sms.
     */
    public const CLIENT_VISIBLE_PROVIDERS = ['twilio', 'alaris', 'amazon_sns'];

    public const FIELDS = [
        'twilio' => [
            ['key' => 'account_sid', 'label' => 'Account SID',  'type' => 'text',     'required' => true],
            ['key' => 'auth_token',  'label' => 'Auth Token',    'type' => 'password', 'required' => true],
            ['key' => 'from_number', 'label' => 'From Number',   'type' => 'text',     'required' => false],
        ],
        'nexmo' => [
            ['key' => 'api_key',    'label' => 'API Key',    'type' => 'text',     'required' => true],
            ['key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'required' => true],
            ['key' => 'from',       'label' => 'From Name',  'type' => 'text',     'required' => false],
        ],
        'messagebird' => [
            ['key' => 'api_key',    'label' => 'API Key',    'type' => 'password', 'required' => true],
            ['key' => 'originator', 'label' => 'Originator', 'type' => 'text',     'required' => false],
        ],
        'smsbd' => [
            ['key' => 'api_key', 'label' => 'API Key',   'type' => 'password', 'required' => true],
            ['key' => 'sender',  'label' => 'Sender ID', 'type' => 'text',     'required' => false],
        ],
        'reve' => [
            ['key' => 'api_key',    'label' => 'API Key',     'type' => 'password', 'required' => true],
            ['key' => 'api_secret', 'label' => 'API Secret',  'type' => 'password', 'required' => true],
            ['key' => 'mask',       'label' => 'Sender Mask', 'type' => 'text',     'required' => false],
        ],
        'alaris' => [
            ['key' => 'base_url', 'label' => 'HTTPS API Base URL', 'type' => 'text', 'required' => true],
            ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID (ANI)', 'type' => 'text', 'required' => true],
            ['key' => 'service_type', 'label' => 'Service Type', 'type' => 'text', 'required' => false],
            ['key' => 'long_message_mode', 'label' => 'Long Message Mode', 'type' => 'text', 'required' => false],
        ],
        'bulksmsbd' => [
            ['key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false],
        ],
        'sms_dot_bd' => [
            ['key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false],
        ],
        'mimsms' => [
            ['key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false],
        ],
        'fast2sms' => [
            ['key' => 'api_key',   'label' => 'API Key',         'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID',       'type' => 'text',     'required' => false],
            ['key' => 'route',     'label' => 'Route (q / dlt)', 'type' => 'text',     'required' => false],
        ],
        'amazon_sns' => [
            ['key' => 'access_key', 'label' => 'AWS Access Key ID',     'type' => 'text',     'required' => true],
            ['key' => 'secret_key', 'label' => 'AWS Secret Access Key', 'type' => 'password', 'required' => true],
            ['key' => 'region',     'label' => 'AWS Region',            'type' => 'text',     'required' => true],
            ['key' => 'sender_id',  'label' => 'Sender ID',             'type' => 'text',     'required' => false],
            ['key' => 'sms_type',   'label' => 'SMS Type (Transactional / Promotional)', 'type' => 'text', 'required' => false],
        ],
    ];

    public const LABELS = [
        'twilio'      => 'Twilio',
        'nexmo'       => 'Vonage / Nexmo',
        'messagebird' => 'MessageBird',
        'smsbd'       => 'SMSBD',
        'reve'        => 'REVE SMS',
        'alaris'      => 'PROSMS',
        'bulksmsbd'   => 'BulkSMS BD',
        'sms_dot_bd'  => 'SMS.BD (sms.net.bd)',
        'mimsms'      => 'MimSMS',
        'fast2sms'    => 'Fast2SMS',
        'amazon_sns'  => 'Amazon SNS',
    ];

    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $configs = SmsProviderConfig::where('workspace_id', $workspaceId)->get()->keyBy('provider');

        $providers = collect(self::CLIENT_VISIBLE_PROVIDERS)->map(fn ($p) => [
            'provider'   => $p,
            'label'      => self::LABELS[$p],
            'fields'     => self::FIELDS[$p],
            'configured' => $configs->has($p) && ! empty($configs->get($p)->credentials),
            'default'    => $configs->get($p)?->default ?? false,
            'sender_id'  => $configs->get($p)?->sender_id ?? '',
            'masked'     => $configs->has($p) ? $this->mask($configs->get($p)->credentials ?? []) : [],
        ]);

        return Inertia::render('Broadcasting/SmsProviders/Index', [
            'providers' => $providers,
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $workspaceId = $this->workspaceId($request);
        $fields = self::FIELDS[$provider];

        $rules = ['sender_id' => ['nullable', 'string', 'max:64'], 'default' => ['boolean']];
        foreach ($fields as $f) {
            $rules['credentials.'.$f['key']] = ['nullable', 'string', 'max:512'];
        }
        if ($provider === 'alaris') {
            // The guide also documents plain HTTP, but credentials must never be
            // sent unencrypted from a production Cerqle installation.
            $rules['credentials.base_url'] = ['required', 'url', 'starts_with:https://', 'max:512'];
        }

        $validated = $request->validate($rules);

        $config = SmsProviderConfig::firstOrNew(['workspace_id' => $workspaceId, 'provider' => $provider]);
        $existing = $config->credentials ?? [];
        $incoming = $validated['credentials'] ?? [];
        $merged = $existing;

        foreach ($incoming as $k => $v) {
            if ($v === null || $v === '' || preg_match('/^•+/', (string) $v)) {
                continue;
            }
            $merged[$k] = $v;
        }

        // PROSMS historically displayed both a provider-specific ANI and the
        // generic Sender ID field. Keep old records compatible while storing
        // one canonical value for the driver.
        if ($provider === 'alaris') {
            $senderId = $validated['sender_id'] ?? null;
            if (filled($senderId)) {
                $merged['sender_id'] = $senderId;
            } elseif (filled($merged['sender_id'] ?? null)) {
                $validated['sender_id'] = $merged['sender_id'];
            }
        }

        // Never persist a partial provider configuration. CredentialResolver
        // treats any non-empty workspace config as usable, so saving only one
        // half of a credential pair would make sends fail later with blanks.
        $missing = collect($fields)
            ->filter(fn (array $field) => (bool) ($field['required'] ?? false))
            ->reject(fn (array $field) => filled($merged[$field['key']] ?? null))
            ->mapWithKeys(fn (array $field) => [
                'credentials.'.$field['key'] => $field['label'].' is required.',
            ])
            ->all();

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        if ($validated['default'] ?? false) {
            SmsProviderConfig::where('workspace_id', $workspaceId)
                ->where('provider', '!=', $provider)
                ->update(['default' => false]);
        }

        $config->fill([
            'credentials' => $merged,
            'sender_id'   => $validated['sender_id'] ?? $config->sender_id,
            'default'     => (bool) ($validated['default'] ?? false),
        ])->save();

        return back()->with('success', self::LABELS[$provider].' configuration saved.');
    }

    public function test(Request $request, string $provider): RedirectResponse
    {
        abort_unless($provider === 'alaris', 404);

        $config = SmsProviderConfig::where('workspace_id', $this->workspaceId($request))
            ->where('provider', $provider)
            ->first();

        if (! $config || empty($config->credentials)) {
            return back()->with('error', 'Save the PROSMS configuration before testing it.');
        }

        $driver = SmsDriverManager::build($provider, $config->credentials);
        abort_unless($driver instanceof AlarisSmsDriver, 422);

        $result = $driver->testConnection();

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            $result['message']
        );
    }

    public function destroy(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $workspaceId = $this->workspaceId($request);
        SmsProviderConfig::where('workspace_id', $workspaceId)->where('provider', $provider)->delete();

        return back()->with('success', self::LABELS[$provider].' configuration removed.');
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    private function mask(array $creds): array
    {
        return collect($creds)->map(fn ($v) => $v === '' ? '' : '••••••••••••')->all();
    }
}
