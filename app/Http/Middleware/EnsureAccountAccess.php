<?php

namespace App\Http\Middleware;

use App\Services\AccessAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && (! $user->is_active || (int) $request->session()->get('access_version', 0) !== $user->session_version)) {
            AccessAudit::record('access.revoked', $user, $user, 'Inactive account or revoked session.');
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['email' => 'Your access has been revoked. Contact the owner.']);
        }

        if ($user?->must_change_password && ! $request->is('account/password', 'logout')) {
            return redirect('/account/password');
        }

        return $next($request);
    }
}
