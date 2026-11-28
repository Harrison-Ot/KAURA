<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AccessAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('owner/employees', [
            'status' => $request->session()->get('status'),
            'employees' => User::query()->where('role', 'employee')->orderBy('name')->get(['id', 'name', 'email', 'is_active', 'must_change_password']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        DB::transaction(function () use ($data, $request): void {
            $employee = new User($data);
            $employee->role = 'employee';
            $employee->must_change_password = true;
            $employee->save();
            AccessAudit::record('employee.created', $request->user(), $employee, 'Owner created employee account.', null, $employee->only(['name', 'email', 'role', 'is_active', 'must_change_password']));
        });

        return back()->with('status', 'Employee created. Share the temporary password securely; it must be changed at first sign-in.');
    }

    public function update(Request $request, User $employee): RedirectResponse
    {
        abort_unless($employee->role === 'employee', 403);
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/']]);

        DB::transaction(function () use ($employee, $data, $request): void {
            $employee = User::query()->lockForUpdate()->findOrFail($employee->id);
            if ($employee->is_active === (bool) $data['is_active']) {
                return;
            }
            $before = $employee->only(['is_active', 'session_version']);
            $employee->is_active = (bool) $data['is_active'];
            $employee->session_version++;
            $employee->setRememberToken(null);
            $employee->save();
            DB::table('sessions')->where('user_id', $employee->id)->delete();
            DB::table('password_reset_tokens')->where('email', $employee->email)->delete();
            AccessAudit::record('employee.'.($employee->is_active ? 'activated' : 'deactivated'), $request->user(), $employee, $data['reason'], $before, $employee->only(['is_active', 'session_version']));
        });

        return back()->with('status', 'Employee access updated. Previous sessions have been revoked.');
    }

    public function audit(): Response
    {
        return Inertia::render('owner/audit', [
            'events' => AuditEvent::query()->leftJoin('users as actors', 'actors.id', '=', 'audit_events.actor_id')
                ->leftJoin('users as subjects', 'subjects.id', '=', 'audit_events.subject_id')
                ->select('audit_events.*', 'actors.name as actor_name', 'subjects.name as subject_name')
                ->orderByDesc('audit_events.id')->paginate(30),
        ]);
    }
}
