<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Services\WorkspaceMembershipService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function __construct(private WorkspaceMembershipService $memberships) {}
    /**
     * Show the invitation acceptance page.
     */
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return redirect()->route('login')->withErrors(['invitation' => 'This invitation is invalid or has expired.']);
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'client' => $invitation->client ? ['name' => $invitation->client->name] : null,
        ]);
    }

    /**
     * Accept the invitation and create/link the user account.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return redirect()->route('login')->withErrors(['invitation' => 'This invitation is invalid or has expired.']);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $user = User::where('email', $invitation->email)->first();

        if ($user) {
            if ($user->client_id && $user->client_id !== $invitation->client_id) {
                return redirect()->route('login')->withErrors([
                    'invitation' => 'This account already belongs to another organization.',
                ]);
            }

            // Existing user — link them to this client, but never overwrite a
            // different organization or widen access outside the invitation.
            if ($invitation->client_id && ! $user->client_id) {
                $user->update([
                    'client_id' => $invitation->client_id,
                    'client_role' => $invitation->client_role ?? User::CLIENT_ROLE_STAFF,
                ]);
            } elseif ($invitation->client_id) {
                $user->update(['client_role' => $invitation->client_role ?? $user->client_role]);
            }
        } else {
            $user = User::create([
                'name' => $request->name,
                'email' => $invitation->email,
                'password' => Hash::make($request->password),
                'role' => 'client',
                'status' => 'active',
                'client_id' => $invitation->client_id,
                'client_role' => $invitation->client_role ?? User::CLIENT_ROLE_STAFF,
                'email_verified_at' => now(),
            ]);

            event(new Registered($user));
        }

        $client = $invitation->client;
        if (! $client) {
            return redirect()->route('login')->withErrors(['invitation' => 'This invitation no longer has an organization.']);
        }

        $assignments = $invitation->workspaces->map(fn ($workspace) => [
            'workspace_id' => $workspace->id,
            'role' => $workspace->pivot->role,
        ])->all();
        $this->memberships->sync($user, $client, $assignments);

        $invitation->update(['accepted_at' => now()]);

        Auth::login($user);

        return redirect()->route('client.dashboard')->with('success', 'Welcome! Your invitation has been accepted.');
    }
}
