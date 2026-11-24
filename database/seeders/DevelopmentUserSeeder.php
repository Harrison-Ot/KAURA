<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AccessAudit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DevelopmentUserSeeder extends Seeder
{
    public const PASSWORD = 'KauraDev!2026';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development accounts may only be seeded in local or testing environments.');
        }
        DB::transaction(function (): void {
            foreach (['owner@kaura.test' => ['Development Owner', 'owner'], 'employee@kaura.test' => ['Development Employee', 'employee']] as $email => [$name, $role]) {
                if (User::query()->where('email', $email)->exists()) {
                    $this->command?->info($email.' already exists; its password and permissions were preserved.');

                    continue;
                }
                $user = new User(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
                $user->role = $role;
                $user->is_active = true;
                $user->must_change_password = false;
                $user->email_verified_at = Carbon::now();
                $user->save();
                AccessAudit::record('development.user_seeded', $user, $user, 'Local development login created; no shop transactions or balances were seeded.', null, $user->only(['email', 'role']), 'user', $user->id);
                $this->command?->info('Created '.$role.' login: '.$email);
            }
        });
    }
}
