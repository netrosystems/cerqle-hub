<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ClientLoginService;
use App\Services\FirebaseIdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FirebaseLoginController extends Controller
{
    public function login(Request $request): JsonResponse|RedirectResponse
    {
        if (SystemSetting::get('firebase_enabled', 'false') !== 'true') {
            return response()->json(['message' => 'Firebase login is not enabled.'], 403);
        }

        $request->validate([
            'id_token' => ['required', 'string', 'max:16384'],
        ]);

        $projectId = SystemSetting::get('firebase_project_id', '');
        if (! $projectId) {
            return response()->json(['message' => 'Firebase project is not configured.'], 500);
        }

        $tokenInfo = app(FirebaseIdTokenVerifier::class)->verify($request->id_token, $projectId);
        if (! $tokenInfo) {
            Log::warning('Firebase login token verification failed', [
                'project_id' => $projectId,
            ]);

            return response()->json(['message' => 'Invalid or expired token.'], 422);
        }

        $email = $tokenInfo['email'] ?? null;
        $uid = $tokenInfo['sub'] ?? null;
        $name = $tokenInfo['name'] ?? $email;
        $avatar = $tokenInfo['picture'] ?? null;

        if (! $email || ! $uid) {
            return response()->json(['message' => 'Could not retrieve email from token.'], 422);
        }

        $existing = SocialAccount::where('provider', 'firebase')
            ->where('provider_id', $uid)
            ->with('user')
            ->first();

        if ($existing) {
            if (! $existing->user || strcasecmp($existing->user->email, $email) !== 0) {
                return response()->json(['message' => 'Account identity does not match.'], 422);
            }
            $challenge = app(ClientLoginService::class)->login($request, $existing->user, true);

            return response()->json(['redirect' => $challenge ?? route('client.dashboard')]);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            if (! config('auth.allow_registration', true)) {
                return response()->json(['message' => 'No account found. Please register first.'], 403);
            }

            $user = DB::transaction(function () use ($name, $email) {
                $client = Client::create([
                    'name' => $name,
                    'email' => $email,
                    'status' => Client::STATUS_ACTIVE,
                    'base_currency' => 'USD',
                    'currency_symbol' => '$',
                    'currency_position' => 'before',
                ]);

                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => bcrypt(Str::random(32)),
                    'role' => User::ROLE_CLIENT,
                    'status' => User::STATUS_ACTIVE,
                    'client_id' => $client->id,
                    'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
                ]);
                // Not mass-assignable, so it must be written directly or it is
                // silently discarded and the user arrives unverified.
                $user->markEmailAsVerified();

                return $user;
            });
        }

        $user->socialAccounts()->create([
            'provider' => 'firebase',
            'provider_id' => $uid,
            'email' => $email,
            'avatar_url' => $avatar,
        ]);

        $challenge = app(ClientLoginService::class)->login($request, $user, true);

        return response()->json(['redirect' => $challenge ?? route('client.dashboard')]);
    }
}
