<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        $roles = fn (Role ...$allowed) => fn (User $user) => $user->is_active && in_array($user->role, $allowed, true);
        Gate::define('manage-catalog', $roles(Role::Admin));
        Gate::define('manage-users', $roles(Role::Admin));
        Gate::define('view-catalog', $roles(Role::Admin, Role::Staff));
        // Finance reads orders and payments but cannot change them.
        Gate::define('view-orders', $roles(Role::Admin, Role::Staff, Role::Finance));
        Gate::define('review-orders', $roles(Role::Admin, Role::Staff));
        Gate::define('cancel-paid-orders', $roles(Role::Admin));
        Gate::define('manage-content', $roles(Role::Admin, Role::ContentEditor));
        Gate::define('view-reports', $roles(Role::Admin, Role::Finance));
        Gate::define('manage-schedule', $roles(Role::Admin, Role::Staff));
        Gate::define('manage-integrations', $roles(Role::Admin));
        RateLimiter::for('midtrans-webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('payment-check', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('report-export', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('staff-login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip()));
        RateLimiter::for('preorder-submit', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
