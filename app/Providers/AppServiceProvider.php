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
        // Always per IP, so made-up tokens cannot buy fresh limits; a configured token gets its own, larger limit.
        RateLimiter::for('api', function (Request $request) {
            $token = (string) $request->bearerToken();
            $known = $token !== '' && collect(config('services.api.tokens', []))->contains(fn ($valid) => hash_equals((string) $valid, $token));

            return $known ? Limit::perMinute(120)->by('token:'.hash('sha256', $token)) : Limit::perMinute(60)->by('ip:'.$request->ip());
        });
        RateLimiter::for('preorder-submit', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        // Sending a new order: unpaid orders hold a place in the outlet quota until H-1 21.00, so floods are capped per
        // minute, hour and day. Mobile networks share IPs, so the daily cap stays well above one family's orders.
        RateLimiter::for('preorder-create', fn (Request $request) => [
            Limit::perMinute(5)->by('min:'.$request->ip()),
            Limit::perHour(15)->by('hour:'.$request->ip()),
            Limit::perDay(20)->by('day:'.$request->ip()),
        ]);
        // The payment page polls every few seconds while a QRIS is open.
        RateLimiter::for('payment-status', fn (Request $request) => Limit::perMinute(40)->by($request->ip()));
        // Choosing VA BCA or QRIS on /bayar creates a DOKU link each time.
        // Erzap calls /web_service/... in batches and checks orders every 2 minutes.
        RateLimiter::for('erzap-inbound', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('payment-choose', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        // Order tracking: a burst limit here; failed codes are also limited in OrderStatusController.
        RateLimiter::for('order-track', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
