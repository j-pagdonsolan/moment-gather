<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Grant all policy checks to Super Admins globally
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->loadMissing('roles')->isAdmin() ? true : null;
        });

        // Named gates for admin sections
        Gate::define('manage-users',         fn (User $u) => $u->loadMissing('roles')->isAdmin());
        Gate::define('manage-events',        fn (User $u) => $u->loadMissing('roles')->isAdmin());
        Gate::define('manage-photos',        fn (User $u) => $u->loadMissing('roles')->isAdmin());
        Gate::define('manage-plans',         fn (User $u) => $u->loadMissing('roles')->isAdmin());
        Gate::define('manage-subscriptions', fn (User $u) => $u->loadMissing('roles')->isAdmin());
        Gate::define('view-payments',        fn (User $u) => $u->loadMissing('roles')->isAdmin());
        Gate::define('view-audit-logs',      fn (User $u) => $u->loadMissing('roles')->isAdmin());

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(
            (int) config('uploads.rate_limit', 10)
        )->by($request->ip()));

        RateLimiter::for('browse', fn (Request $request) => Limit::perMinute(
            (int) config('uploads.browse_rate_limit', 60)
        )->by($request->ip()));
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
