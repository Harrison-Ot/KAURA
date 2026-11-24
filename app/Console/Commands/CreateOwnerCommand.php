<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccessAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateOwnerCommand extends Command
{
    protected $signature = 'kaura:create-owner';

    protected $description = 'Create the initial owner interactively; never pass passwords as command arguments';

    public function handle(): int
    {
        if (User::query()->where('role', 'owner')->exists()) {
            $this->error('An owner already exists. No account was changed.');

            return self::FAILURE;
        }
        $data = [
            'name' => $this->ask('Owner name'),
            'email' => Str::lower(trim((string) $this->ask('Owner email'))),
            'password' => $this->secret('Temporary password (12+ characters, upper/lowercase, number and symbol)'),
            'password_confirmation' => $this->secret('Confirm temporary password'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        return Cache::lock('kaura-owner-setup', 30)->block(5, function () use ($data): int {
            return DB::transaction(function () use ($data): int {
                if (User::query()->where('role', 'owner')->exists()) {
                    $this->error('An owner already exists.');

                    return self::FAILURE;
                }
                $owner = new User($data);
                $owner->role = 'owner';
                $owner->email_verified_at = Carbon::now();
                $owner->must_change_password = true;
                $owner->save();
                AccessAudit::record('owner.created', $owner, $owner, 'Initial owner created through local console.', null, $owner->only(['name', 'email', 'role']));
                $this->info('Owner created. Sign in and replace the temporary password.');

                return self::SUCCESS;
            });
        });
    }
}
