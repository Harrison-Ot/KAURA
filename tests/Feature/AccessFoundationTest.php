<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccessFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'Intruder', 'role' => 'owner'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_employee_cannot_access_owner_routes_or_create_accounts(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee)->get('/owner/employees')->assertForbidden();
        $this->get('/owner/audit')->assertForbidden();
        $this->post('/owner/employees', $this->employeeData())->assertForbidden();
        $this->patch('/owner/employees/'.$employee->id, ['is_active' => false, 'reason' => 'Forged'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_events', 4);
    }

    public function test_guests_are_redirected_and_owner_can_read_screens(): void
    {
        $this->get('/owner/employees')->assertRedirect('/login');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->get('/owner/employees')->assertInertia(fn (Assert $page) => $page->component('owner/employees')->has('employees', 0));
        $this->get('/owner/audit')->assertInertia(fn (Assert $page) => $page->component('owner/audit')->has('events.data', 0));
    }

    public function test_owner_creates_only_employee_and_audit_excludes_secrets(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->from('/owner/employees')->post('/owner/employees', [...$this->employeeData(), 'role' => 'owner', 'is_active' => false])->assertRedirect('/owner/employees');
        $employee = User::query()->where('email', 'staff@example.com')->firstOrFail();
        $this->assertSame('employee', $employee->role);
        $this->assertTrue($employee->must_change_password);
        $this->assertTrue($employee->is_active);
        $this->assertTrue(Hash::check('Temporary123!', $employee->password));
        $event = DB::table('audit_events')->first();
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertArrayNotHasKey('password', json_decode($event->after_state, true));
        $this->assertStringNotContainsString('Temporary123!', $event->after_state);
    }

    public function test_duplicate_email_and_weak_password_are_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        User::factory()->create(['email' => 'staff@example.com']);
        $this->actingAs($owner)->post('/owner/employees', [...$this->employeeData(), 'email' => 'STAFF@example.com', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_deactivation_is_reasoned_idempotent_and_preserves_account(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $employee = User::factory()->create(['remember_token' => 'old-token']);
        DB::table('sessions')->insert(['id' => 'staff-session', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($owner)->patch('/owner/employees/'.$employee->id, ['is_active' => false, 'reason' => ' '])->assertSessionHasErrors('reason');
        $data = ['is_active' => false, 'reason' => 'Employment ended'];
        $this->patch('/owner/employees/'.$employee->id, $data)->assertRedirect();
        $this->patch('/owner/employees/'.$employee->id, $data)->assertRedirect();
        $this->assertFalse($employee->refresh()->is_active);
        $this->assertSame(1, $employee->session_version);
        $this->assertNull($employee->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'staff-session']);
        $this->assertDatabaseHas('audit_events', ['event' => 'employee.deactivated', 'subject_id' => $employee->id, 'reason' => 'Employment ended']);
        $this->assertDatabaseCount('audit_events', 1);
        $this->patch('/owner/employees/'.$owner->id, $data)->assertForbidden();
    }

    public function test_inactive_login_fails_and_existing_session_is_rejected(): void
    {
        $employee = User::factory()->create(['is_active' => false]);
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($employee)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_revoked_session_stays_invalid_after_reactivation(): void
    {
        $employee = User::factory()->create(['session_version' => 2]);
        $this->actingAs($employee)->withSession(['access_version' => 0])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_initial_password_gate_and_change(): void
    {
        $employee = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($employee)->get('/dashboard')->assertRedirect('/account/password');
        $this->get('/owner/employees')->assertRedirect('/account/password');
        $this->get('/account/password')->assertOk();
        $this->put('/account/password', ['current_password' => 'wrong', 'password' => 'NewPrivate123!', 'password_confirmation' => 'NewPrivate123!'])->assertSessionHasErrors('current_password');
        $this->put('/account/password', ['current_password' => 'password', 'password' => 'NewPrivate123!', 'password_confirmation' => 'NewPrivate123!'])->assertRedirect('/dashboard');
        $this->assertFalse($employee->refresh()->must_change_password);
        $this->assertTrue(Hash::check('NewPrivate123!', $employee->password));
        $this->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('audit_events', ['event' => 'account.password_changed', 'actor_id' => $employee->id]);
    }

    public function test_owner_setup_is_interactive_and_cannot_be_repeated(): void
    {
        $this->artisan('kaura:create-owner')->expectsQuestion('Owner name', 'Owner')
            ->expectsQuestion('Owner email', 'OWNER@example.com')
            ->expectsQuestion('Temporary password (12+ characters, upper/lowercase, number and symbol)', 'Temporary123!')
            ->expectsQuestion('Confirm temporary password', 'Temporary123!')->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'role' => 'owner', 'must_change_password' => true]);
        $this->artisan('kaura:create-owner')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    /** @return array<string, string> */
    private function employeeData(): array
    {
        return ['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'Temporary123!', 'password_confirmation' => 'Temporary123!'];
    }
}
