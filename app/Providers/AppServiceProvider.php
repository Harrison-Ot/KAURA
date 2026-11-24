<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AccessAudit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                request()->session()->put('access_version', $event->user->session_version);
                AccessAudit::record('auth.login', $event->user, $event->user, 'Successful authentication.');
            }
        });
        Event::listen(Failed::class, function (Failed $event): void {
            $subject = $event->user instanceof User ? $event->user : null;
            AccessAudit::record('auth.failed', null, $subject, 'Authentication failed.');
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
