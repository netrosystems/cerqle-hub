<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Auth;

class AuthenticateActiveAccount extends Authenticate
{
    /** @param array<int, string|null> $guards */
    protected function authenticate($request, array $guards): void
    {
        parent::authenticate($request, $guards);
        $user = $request->user();

        if (($user instanceof User && ! $user->canAuthenticate())
            || ($user instanceof AdminUser && ! $user->isActive())) {
            if ($user instanceof User) {
                $user->tokens()->delete();
            }
            if ($request->hasSession()) {
                Auth::guard($user instanceof AdminUser ? 'admin' : 'web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            $this->unauthenticated($request, $guards);
        }
    }
}
