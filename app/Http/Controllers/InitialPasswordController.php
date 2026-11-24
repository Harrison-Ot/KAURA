<?php

namespace App\Http\Controllers;

use App\Services\AccessAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class InitialPasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('auth/initial-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $user = $request->user();
        DB::transaction(function () use ($user, $data): void {
            $user->password = $data['password'];
            $user->must_change_password = false;
            $user->session_version++;
            $user->setRememberToken(null);
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            AccessAudit::record('account.password_changed', $user, $user, 'Initial password replaced.');
        });
        $request->session()->regenerate();
        $request->session()->put('access_version', $user->session_version);

        return to_route('dashboard');
    }
}
