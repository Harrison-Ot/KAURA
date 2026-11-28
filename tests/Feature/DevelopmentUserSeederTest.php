<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevelopmentUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_development_accounts_can_log_in_without_password_setup(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (['owner', 'employee'] as $role) {
            $user = User::where('email', $role.'@kaura.test')->sole();
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->is_active);
            $this->assertFalse($user->must_change_password);
            $this->assertNotNull($user->email_verified_at);
            $this->post('/login', ['email' => $user->email, 'password' => DevelopmentUserSeeder::PASSWORD])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user);
            $this->get('/dashboard')->assertOk();
            $this->post('/logout');
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('receivables', 0);
    }

    public function test_rerunning_preserves_existing_password_and_permissions(): void
    {
        $this->seed(DevelopmentUserSeeder::class);
        $user = User::where('email', 'owner@kaura.test')->sole();
        $user->forceFill(['password' => 'ChangedSecret!123', 'role' => 'employee', 'is_active' => false])->save();
        $this->seed(DevelopmentUserSeeder::class);
        $user->refresh();
        $this->assertTrue(Hash::check('ChangedSecret!123', $user->password));
        $this->assertSame('employee', $user->role);
        $this->assertFalse($user->is_active);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_production_default_seeder_is_inert_and_explicit_dev_seeding_refuses(): void
    {
        $this->app->instance('env', 'production');
        try {
            (new DatabaseSeeder)->run();
            $this->assertDatabaseCount('users', 0);
            $this->expectException(\RuntimeException::class);
            (new DevelopmentUserSeeder)->run();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
