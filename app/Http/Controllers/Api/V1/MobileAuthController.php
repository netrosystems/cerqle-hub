<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StorageManager;
use App\Services\UserPushTokenService;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function __construct(
        private StorageManager $storageManager,
        private UserPushTokenService $pushTokens,
    ) {}

    /**
     * POST /api/v1/auth/login
     * Issues a Sanctum personal access token for mobile clients.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'push_token' => ['nullable', 'string', 'max:255'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $throttleKey = $this->failedLoginThrottleKey($email, $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return $this->rateLimitedResponse(
                RateLimiter::availableIn($throttleKey),
            );
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is not active.'], 403);
        }

        $deviceName = $validated['device_name'] ?? 'ChatAgent Mobile';
        $token = $user->createToken($deviceName, ['*'])->plainTextToken;
        $this->pushTokens->register($user, $validated['push_token'] ?? $validated['device_id'] ?? null, $deviceName);

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    private function failedLoginThrottleKey(string $email, string $ip): string
    {
        return 'mobile-login:failed:'.hash('sha256', $email.'|'.$ip);
    }

    private function rateLimitedResponse(int $retryAfter): JsonResponse
    {
        $retryAfter = max(1, $retryAfter);

        return response()->json([
            'code' => 'login_rate_limited',
            'message' => "Too many login attempts. Please try again in {$retryAfter} seconds.",
            'retry_after' => $retryAfter,
        ], 429, [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => '5',
            'X-RateLimit-Remaining' => '0',
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     * Revokes the current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/v1/auth/me
     * Full authenticated user profile for mobile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('workspace');

        return response()->json($this->userPayload($user, withWorkspace: true));
    }

    /**
     * POST /api/v1/auth/profile
     * Update the signed-in agent's display name and/or avatar photo.
     * Multipart: name (optional), avatar (optional image file), remove_avatar (optional bool).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        if ($request->filled('name')) {
            $user->name = $validated['name'];
        }

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $storedPath = $this->storageManager->prefixedPath('avatars/'.$file->hashName());
            $this->storageManager->disk()->putFileAs(
                dirname($storedPath),
                $file,
                basename($storedPath),
            );
            $user->avatar = $storedPath;
        } elseif ($request->boolean('remove_avatar')) {
            $user->avatar = null;
        }

        $user->save();
        $user->loadMissing('workspace');

        return response()->json($this->userPayload($user, withWorkspace: true));
    }

    /**
     * Shared serialisation for the agent profile.
     */
    private function userPayload(User $user, bool $withWorkspace = false): array
    {
        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'workspace_id' => $user->workspace_id,
            'current_workspace_id' => $user->workspace_id,
            'avatar' => $user->avatarUrl(),
            'demo_mode' => Demo::active(),
            'push' => [
                'provider' => 'onesignal',
                'enabled' => $this->oneSignal->isConfigured(),
                'app_id' => $this->oneSignal->publicAppId(),
                'external_id' => 'user:'.$user->id,
            ],
        ];

        if ($withWorkspace) {
            $payload['workspace'] = $user->workspace ? [
                'id' => $user->workspace->id,
                'name' => $user->workspace->name,
            ] : null;
        }

        return $payload;
    }
}
