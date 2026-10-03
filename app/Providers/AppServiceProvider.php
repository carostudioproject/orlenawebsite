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
        // Admin (owner) and Developer have full access; Developer also gets Developer tools.
        $full = [Role::Admin, Role::Developer];
        Gate::define('manage-catalog', $roles(...$full));
        Gate::define('manage-users', $roles(...$full));
        Gate::define('view-catalog', $roles(Role::Staff, ...$full));
        // Finance reads orders and payments but cannot change them.
        Gate::define('view-orders', $roles(Role::Staff, Role::Finance, ...$full));
        // Only the owner (Admin) and Staff handle orders: confirm, payments, status and cancellation.
        Gate::define('review-orders', $roles(Role::Admin, Role::Staff));
        Gate::define('cancel-paid-orders', $roles(Role::Admin));
        Gate::define('manage-content', $roles(Role::ContentEditor, ...$full));
        Gate::define('view-reports', $roles(Role::Finance, ...$full));
        Gate::define('manage-schedule', $roles(Role::Staff, ...$full));
        Gate::define('manage-integrations', $roles(...$full));
        Gate::define('manage-system', $roles(Role::Developer));
        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('payment-check', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('report-export', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('staff-login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip()));
        RateLimiter::for('preorder-submit', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        // The payment page polls every few seconds while a QRIS is open.
        RateLimiter::for('payment-status', fn (Request $request) => Limit::perMinute(40)->by($request->ip()));
        // Order tracking: a burst limit here; failed codes are also limited in OrderStatusController.
        RateLimiter::for('order-track', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
