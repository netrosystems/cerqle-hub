<?php

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\CoexistenceRollout;
use App\Services\ChannelPlanLimitService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Separate from standard signup: never registers, deregisters, or prunes phones. */
class WhatsappCoexistenceController extends Controller
{
    public function begin(Request $request): JsonResponse
    {
        abort_unless(CoexistenceRollout::enabledFor((int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id)), 404);
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        $workspace = Workspace::findOrFail($workspaceId);
        // Identity is selected in Meta. Allow reauthorization at capacity; model
        // enforcement still checks new identities under the billing lock at save.
        $existingChannel = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'whatsapp')->exists();
        if (! $existingChannel) {
            $limits = app(ChannelPlanLimitService::class);
            $limits->ensureCapacity($limits->usage($workspace, 'messaging_channels'));
        }
        $id = (string) Str::uuid();
        $request->session()->put('whatsapp.coexistence_attempt', [
            'id' => $id, 'actor' => $request->user()->id,
            'workspace' => $request->user()->current_workspace_id ?? $request->user()->workspace_id,
            'expires_at' => now()->addMinutes(30)->timestamp,
        ]);

        return response()->json(['attempt_id' => $id]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(CoexistenceRollout::enabledFor((int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id)), 404);
        $data = $request->validate([
            'attempt_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:2048'],
            'waba_id' => ['required', 'regex:/^[0-9]{1,64}$/'],
            'phone_number_id' => ['nullable', 'regex:/^[0-9]{1,64}$/'],
        ]);
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        $attempt = $request->session()->get('whatsapp.coexistence_attempt');
        abort_unless(is_array($attempt) && ($attempt['id'] ?? '') === $data['attempt_id']
            && ($attempt['actor'] ?? null) === $request->user()->id
            && (int) ($attempt['workspace'] ?? 0) === $workspaceId
            && ($attempt['expires_at'] ?? 0) > now()->timestamp, 409, 'Restart WhatsApp setup in this workspace.');

        $lock = Cache::lock('whatsapp-coexistence:'.$data['attempt_id'], 900);
        abort_unless($lock->get(), 409, 'WhatsApp setup is already processing.');
        try {
            $meta = CredentialResolver::system()->meta();
            abort_unless($meta?->appId() && $meta->appSecret(), 422, 'Meta app is not configured.');
            $base = 'https://graph.facebook.com/'.config('whatsapp.coexistence_graph_version');
            // Consume before exchanging a single-use code. Ambiguous failures require a new attempt.
            abort_unless(Cache::add('whatsapp-coexistence-consumed:'.$data['attempt_id'], true, 3600),
                409, 'This setup attempt was already used. Restart WhatsApp setup.');
            $request->session()->forget('whatsapp.coexistence_attempt');
            $tokenResponse = Http::timeout(30)->get($base.'/oauth/access_token', [
                'client_id' => $meta->appId(), 'client_secret' => $meta->appSecret(), 'code' => $data['code'],
            ]);
            $token = $tokenResponse->json('access_token');
            abort_unless($tokenResponse->successful() && is_string($token) && $token !== '', 422, 'Meta code exchange failed. Restart setup.');
            $debug = Http::withToken($meta->appId().'|'.$meta->appSecret())->timeout(30)
                ->get($base.'/debug_token', ['input_token' => $token]);
            abort_unless($debug->successful() && $debug->json('data.is_valid') === true
                && (string) $debug->json('data.app_id') === (string) $meta->appId(), 422, 'Meta returned an invalid authorization.');
            $scopes = $debug->json('data.scopes', []);
            abort_unless(is_array($scopes) && in_array('whatsapp_business_management', $scopes, true)
                && in_array('whatsapp_business_messaging', $scopes, true), 422, 'Authorize WhatsApp management and messaging access.');

            $wabaId = $data['waba_id'];
            $wabaResponse = Http::withToken($token)->timeout(30)->get($base.'/'.$wabaId, ['fields' => 'id,name']);
            abort_unless($wabaResponse->successful() && (string) $wabaResponse->json('id') === $wabaId,
                422, 'The selected WhatsApp account is not accessible.');
            $matches = [];
            $after = null;
            $seen = [];
            do {
                $params = ['fields' => 'id,display_phone_number,verified_name,is_on_biz_app,platform_type', 'limit' => 100];
                if ($after !== null) {
                    $params['after'] = $after;
                }
                $response = Http::withToken($token)->timeout(30)->get($base.'/'.$wabaId.'/phone_numbers', $params);
                abort_unless($response->successful(), 422, 'Could not verify WhatsApp phone ownership.');
                foreach ($response->json('data', []) as $row) {
                    if (($row['is_on_biz_app'] ?? false) === true
                        && ($row['platform_type'] ?? '') === 'CLOUD_API'
                        && (empty($data['phone_number_id']) || (string) ($row['id'] ?? '') === $data['phone_number_id'])) {
                        $matches[] = $row;
                    }
                }
                $after = $response->json('paging.next') ? $response->json('paging.cursors.after') : null;
                abort_if($after !== null && (! is_string($after) || isset($seen[$after]) || count($seen) >= 20),
                    422, 'Phone discovery could not complete safely.');
                if ($after !== null) {
                    $seen[$after] = true;
                }
            } while ($after !== null);
            abort_if(count($matches) > 1, 422,
                'Meta returned multiple Business app numbers without a unique selection. Restart setup and select a single number.');
            abort_unless(count($matches) === 1 && ($matches[0]['is_on_biz_app'] ?? false) === true
                && ($matches[0]['platform_type'] ?? '') === 'CLOUD_API',
                422, 'Meta has not confirmed coexistence for the selected Business app number. Do not delete or migrate it.');
            $row = $matches[0];
            $phoneId = (string) ($row['id'] ?? '');
            abort_unless(preg_match('/^[0-9]{1,64}$/', $phoneId), 422, 'Meta returned an invalid phone ID.');
            $waba = DB::transaction(function () use ($workspaceId, $wabaId, $phoneId, $row, $token, $wabaResponse) {
                $workspace = Workspace::findOrFail($workspaceId);
                app(ChannelPlanLimitService::class)->account($workspace, true);
                $waba = WhatsappBusinessAccount::where('waba_id', $wabaId)->lockForUpdate()->first();
                abort_if($waba && (int) $waba->workspace_id !== $workspaceId, 409, 'This WhatsApp account belongs to another workspace.');
                $phone = WhatsappPhoneNumber::where('phone_number_id', $phoneId)->lockForUpdate()->first();
                abort_if($phone && (! $waba || (int) $phone->waba_id_fk !== (int) $waba->id), 409, 'This phone is already connected elsewhere.');
                abort_if(ChannelAccount::where('channel', 'whatsapp')->where('phone_number_id', $phoneId)
                    ->where('workspace_id', '!=', $workspaceId)->exists(), 409, 'This phone belongs to another workspace.');
                $waba ??= new WhatsappBusinessAccount(['waba_id' => $wabaId, 'workspace_id' => $workspaceId]);
                $waba->credentials = array_merge($waba->credentials ?? [], [
                    'system_user_token' => $token, 'access_token' => $token, 'token_source' => 'embedded_signup',
                ]);
                $waba->webhook_verify_token ??= Str::random(48);
                $waba->status = 'active';
                $waba->meta_json = array_merge($waba->meta_json ?? [], ['display_name' => $wabaResponse->json('name')]);
                $waba->save();
                $phone ??= new WhatsappPhoneNumber(['phone_number_id' => $phoneId]);
                $phone->fill(['waba_id_fk' => $waba->id, 'display_phone' => $row['display_phone_number'],
                    'verified_name' => $row['verified_name'] ?? null, 'connection_mode' => 'coexistence']);
                $phone->coexistence_meta = array_merge($phone->coexistence_meta ?? [], [
                    'verified_at' => now()->toIso8601String(), 'import_consent' => false, 'disconnected_at' => null,
                ]);
                $phone->save();
                ChannelAccount::updateOrCreate([
                    'workspace_id' => $workspaceId, 'channel' => 'whatsapp', 'phone_number_id' => $phoneId,
                ], ['provider' => 'meta', 'business_account_id' => $wabaId, 'status' => 'active',
                    'display_name' => mb_substr($row['verified_name'] ?? $row['display_phone_number'], 0, 128)]);

                return $waba;
            });
            $subscription = Http::withToken($token)->timeout(30)->post($base.'/'.$wabaId.'/subscribed_apps');
            $warning = ! $subscription->successful() || $subscription->json('success') !== true;
            $waba->update(['meta_json' => array_merge($waba->meta_json ?? [], ['webhook_ready' => ! $warning])]);

            return response()->json(['success' => true, 'message' => 'WhatsApp Business app connected.',
                'connection_mode' => 'coexistence', 'phone_count' => 1,
                'webhook_warning' => $warning ? 'Webhook subscription needs attention. Incoming messages may not appear yet.' : null]);
        } catch (ConnectionException) {
            // Do not log provider exceptions: OAuth request URLs may contain secrets.
            return response()->json(['message' => 'Meta could not be reached. Check Channel Setup before restarting; the connection may have been saved.'], 502);
        } finally {
            $lock->release();
        }
    }
}
