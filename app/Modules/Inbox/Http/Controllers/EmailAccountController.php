<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Inbox\Services\GenericMailboxClient;
use App\Modules\Inbox\Services\GoogleGmailClient;
use App\Modules\Inbox\Services\MicrosoftGraphMailClient;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EmailAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $google = IntegrationConfig::forProvider('oauth_google_mail');
        $microsoft = IntegrationConfig::forProvider('oauth_microsoft_365');

        return Inertia::render('Inbox/EmailSetup', [
            'accounts' => ChannelAccount::where('workspace_id', $workspaceId)
                ->where('channel', 'email')->latest()->get()
                ->map(fn (ChannelAccount $account) => [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'display_name' => $account->display_name,
                    'status' => $account->status,
                    'email' => $account->meta_json['email'] ?? null,
                    'last_synced_at' => $account->meta_json['last_synced_at'] ?? null,
                    'last_sync_error' => $account->meta_json['last_sync_error'] ?? null,
                ]),
            'microsoftEnabled' => (bool) ($microsoft?->enabled && $microsoft->isConfigured()),
            'microsoftCallbackUrl' => route('client.inbox.email.microsoft.callback'),
            'googleEnabled' => (bool) ($google?->enabled && $google->isConfigured()),
            'googleCallbackUrl' => route('client.inbox.email.google.callback'),
            'imapExtensionAvailable' => function_exists('imap_open'),
        ]);
    }

    public function connectGoogle(Request $request, GoogleGmailClient $client): RedirectResponse
    {
        $state = Str::random(64);
        $request->session()->put('google_mail_oauth', [
            'state' => hash('sha256', $state),
            'workspace_id' => $this->workspaceId($request),
            'user_id' => $request->user()->id,
            'created_at' => now()->timestamp,
        ]);

        return redirect()->away($client->authorizationUrl($state, route('client.inbox.email.google.callback')));
    }

    public function googleCallback(Request $request, GoogleGmailClient $client): RedirectResponse
    {
        $pending = $request->session()->pull('google_mail_oauth');
        if (! is_array($pending)
            || ! hash_equals((string) ($pending['state'] ?? ''), hash('sha256', (string) $request->query('state')))
            || (int) ($pending['user_id'] ?? 0) !== (int) $request->user()->id
            || (int) ($pending['created_at'] ?? 0) < now()->subMinutes(10)->timestamp) {
            return to_route('client.inbox.email.index')->with('error', 'Google authorization expired or had an invalid state. Please try again.');
        }
        if ($request->filled('error')) {
            return to_route('client.inbox.email.index')->with('error', (string) $request->query('error_description', 'Google authorization was cancelled.'));
        }

        try {
            $tokens = $client->exchangeCode((string) $request->query('code'), route('client.inbox.email.google.callback'));
            $profile = $client->profile($tokens['access_token']);
            $email = strtolower((string) ($profile['email'] ?? ''));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || empty($profile['sub'])) {
                throw new \RuntimeException('Google did not return a usable mailbox identity.');
            }
            $existing = ChannelAccount::where([
                'workspace_id' => (int) $pending['workspace_id'],
                'channel' => 'email',
                'provider' => 'gmail',
                'business_account_id' => (string) $profile['sub'],
            ])->first();
            $refreshToken = $tokens['refresh_token'] ?? data_get($existing?->credentials, 'refresh_token');
            if (! $refreshToken) {
                throw new \RuntimeException('Google did not issue offline access. Revoke the app in your Google Account and reconnect.');
            }
            $account = ChannelAccount::updateOrCreate(
                [
                    'workspace_id' => (int) $pending['workspace_id'],
                    'channel' => 'email',
                    'provider' => 'gmail',
                    'business_account_id' => (string) $profile['sub'],
                ],
                [
                    'display_name' => (string) ($profile['name'] ?? $email),
                    'status' => 'active',
                    'credentials' => [
                        'access_token' => $tokens['access_token'],
                        'refresh_token' => $refreshToken,
                        'expires_at' => now()->addSeconds(max(60, ((int) ($tokens['expires_in'] ?? 3600)) - 60))->toIso8601String(),
                    ],
                    'meta_json' => array_merge($existing?->meta_json ?? [], ['email' => $email, 'last_sync_error' => null]),
                ],
            );
            SyncEmailAccountJob::dispatch($account->id)->onQueue('default');

            return to_route('client.inbox.email.index')->with('success', 'Gmail mailbox connected. Initial sync has started.');
        } catch (Throwable $e) {
            return to_route('client.inbox.email.index')->with('error', $e->getMessage());
        }
    }

    public function connectMicrosoft(Request $request, MicrosoftGraphMailClient $client): RedirectResponse
    {
        $state = Str::random(64);
        $request->session()->put('microsoft_mail_oauth', [
            'state' => hash('sha256', $state),
            'workspace_id' => $this->workspaceId($request),
            'user_id' => $request->user()->id,
            'created_at' => now()->timestamp,
        ]);

        return redirect()->away($client->authorizationUrl($state, route('client.inbox.email.microsoft.callback')));
    }

    public function microsoftCallback(Request $request, MicrosoftGraphMailClient $client): RedirectResponse
    {
        $pending = $request->session()->pull('microsoft_mail_oauth');
        if (! is_array($pending)
            || ! hash_equals((string) ($pending['state'] ?? ''), hash('sha256', (string) $request->query('state')))
            || (int) ($pending['user_id'] ?? 0) !== (int) $request->user()->id
            || (int) ($pending['created_at'] ?? 0) < now()->subMinutes(10)->timestamp) {
            return to_route('client.inbox.email.index')->with('error', 'Microsoft authorization expired or had an invalid state. Please try again.');
        }
        if ($request->filled('error')) {
            return to_route('client.inbox.email.index')->with('error', (string) $request->query('error_description', 'Microsoft authorization was cancelled.'));
        }

        try {
            $tokens = $client->exchangeCode((string) $request->query('code'), route('client.inbox.email.microsoft.callback'));
            $profile = $client->profile($tokens['access_token']);
            $email = strtolower((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Microsoft did not return a usable mailbox email address.');
            }
            $account = ChannelAccount::updateOrCreate(
                [
                    'workspace_id' => (int) $pending['workspace_id'],
                    'channel' => 'email',
                    'provider' => 'microsoft_365',
                    'business_account_id' => (string) $profile['id'],
                ],
                [
                    'display_name' => (string) ($profile['displayName'] ?: $email),
                    'status' => 'active',
                    'credentials' => [
                        'access_token' => $tokens['access_token'],
                        'refresh_token' => $tokens['refresh_token'] ?? null,
                        'expires_at' => now()->addSeconds(max(60, ((int) ($tokens['expires_in'] ?? 3600)) - 60))->toIso8601String(),
                    ],
                    'meta_json' => ['email' => $email, 'last_sync_error' => null],
                ],
            );
            SyncEmailAccountJob::dispatch($account->id)->onQueue('default');

            return to_route('client.inbox.email.index')->with('success', 'Microsoft 365 mailbox connected. Initial sync has started.');
        } catch (Throwable $e) {
            return to_route('client.inbox.email.index')->with('error', $e->getMessage());
        }
    }

    public function storeGeneric(Request $request, GenericMailboxClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:128'],
            'imap_host' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i'],
            'imap_port' => ['required', 'integer', Rule::in([143, 993])],
            'imap_encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'smtp_host' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i'],
            'smtp_port' => ['required', 'integer', Rule::in([25, 465, 587])],
            'smtp_encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'verify_tls' => ['sometimes', 'boolean'],
        ]);
        $workspaceId = $this->workspaceId($request);
        $email = strtolower($validated['email']);
        $credentials = collect($validated)->except(['email', 'display_name'])->all();
        $credentials['verify_tls'] = $validated['verify_tls'] ?? true;

        // Validate against an unsaved model first. A typo must not overwrite a
        // previously working mailbox or persist an invalid password.
        $pendingAccount = new ChannelAccount([
            'workspace_id' => $workspaceId,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'business_account_id' => $email,
            'display_name' => $validated['display_name'] ?: $email,
            'status' => 'inactive',
            'credentials' => $credentials,
            'meta_json' => ['email' => $email],
        ]);

        try {
            $client->verify($pendingAccount);
            $account = ChannelAccount::updateOrCreate(
                ['workspace_id' => $workspaceId, 'channel' => 'email', 'provider' => 'imap_smtp', 'business_account_id' => $email],
                [
                    'display_name' => $pendingAccount->display_name,
                    'status' => 'active',
                    'credentials' => $credentials,
                    'meta_json' => ['email' => $email, 'last_sync_error' => null],
                ],
            );
            SyncEmailAccountJob::dispatch($account->id)->onQueue('default');

            return back()->with('success', 'IMAP and SMTP connected. Initial sync has started.');
        } catch (Throwable $e) {
            return back()
                ->withErrors(['connection' => $e->getMessage()])
                ->withInput($request->except('password'));
        }
    }

    public function sync(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $this->authorise($request, $channelAccount);
        $updates = ['status' => 'active'];
        if ($channelAccount->provider === 'imap_smtp') {
            // Manual sync is a bounded seven-day refresh. This lets the new
            // MIME parser repair recently imported messages without making the
            // minute-by-minute scheduled sync repeatedly scan old mail.
            $updates['meta_json'] = collect($channelAccount->meta_json ?? [])
                ->except(['last_synced_at', 'last_sync_error'])
                ->all();
        }
        $channelAccount->update($updates);
        SyncEmailAccountJob::dispatch($channelAccount->id, true)->onQueue('default');

        return back()->with('success', $channelAccount->provider === 'imap_smtp'
            ? 'Recent mailbox refresh queued.'
            : 'Mailbox sync queued.');
    }

    public function destroy(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $this->authorise($request, $channelAccount);
        $channelAccount->delete();

        return back()->with('success', 'Mailbox disconnected. Existing conversations were kept.');
    }

    public function compose(
        Request $request,
        GoogleGmailClient $google,
        MicrosoftGraphMailClient $microsoft,
        GenericMailboxClient $generic,
    ): RedirectResponse {
        $validated = $request->validate([
            'channel_account_id' => ['required', 'integer'],
            'to' => ['required', 'email', 'max:255'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'bcc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:998'],
            'body' => ['required', 'string', 'max:100000'],
        ]);
        $workspaceId = $this->workspaceId($request);
        $account = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'email')
            ->where('status', 'active')
            ->findOrFail($validated['channel_account_id']);
        $cc = $this->emailList($validated['cc'] ?? '');
        $bcc = $this->emailList($validated['bcc'] ?? '');
        $recipient = strtolower(trim((string) $validated['to']));

        $contact = Contact::firstOrCreate(
            ['workspace_id' => $workspaceId, 'email' => $recipient],
            ['first_name' => Str::before($recipient, '@'), 'source' => 'email', 'opt_in_email' => false],
        );
        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'external_thread_id' => 'outbound:'.Str::uuid(),
            'status' => 'open',
            'assigned_to' => 'human',
            'assigned_user_id' => $request->user()->id,
            'last_message_at' => now(),
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => 'text',
            'body' => $validated['body'],
            'payload' => ['subject' => $validated['subject'], 'to' => $recipient, 'cc' => $cc, 'bcc' => $bcc],
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        try {
            $providerId = match ($account->provider) {
                'gmail' => $google->send($account, $recipient, $validated['subject'], $validated['body']),
                'microsoft_365' => $microsoft->sendMessage($account, $recipient, $validated['subject'], $validated['body']),
                default => $generic->send($account, $recipient, $validated['subject'], $validated['body'], null, $cc, $bcc),
            };
            $message->update(['status' => 'sent', 'provider_message_id' => $providerId]);
            MessageSent::dispatch($message->load('conversation'));

            return redirect()->route('client.inbox.email-inbox', ['conversation' => $conversation->uuid])
                ->with('success', 'Email sent.');
        } catch (Throwable $e) {
            $message->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
            Log::warning('Email compose failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['compose' => $e->getMessage()])->withInput();
        }
    }

    private function emailList(string $value): array
    {
        $emails = array_values(array_filter(array_map(
            fn (string $email) => strtolower(trim($email)),
            preg_split('/[,;]+/', $value) ?: [],
        )));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'cc' => 'Every CC and BCC recipient must be a valid email address.',
                ]);
            }
        }

        return array_values(array_unique($emails));
    }

    private function authorise(Request $request, ChannelAccount $account): void
    {
        abort_unless($account->channel === 'email' && $account->workspace_id === $this->workspaceId($request), 404);
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
