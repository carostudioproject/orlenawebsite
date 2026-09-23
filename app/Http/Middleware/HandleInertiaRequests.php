<?php

namespace App\Http\Middleware;

use App\Support\PreorderDate;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        if (! $request->is('admin', 'admin/*')) {
            // Social links and the WhatsApp number are edited once in the dashboard and used on every public page.
            return [...parent::share($request), 'site' => fn () => app(SiteContent::class)->get('social'), 'poCutoff' => fn () => app(PreorderDate::class)->cutoffLabel()];
        }

        $user = $request->user();
        // Only for showing/hiding UI; every route still authorizes on the server.
        $can = fn (string $ability) => $user?->can($ability) ?? false;

        return [...parent::share($request),
            'auth' => [
                'user' => $user ? [...$user->only('id', 'name', 'username', 'role'), 'role_label' => $user->role->label()] : null,
                'can_manage' => $can('manage-catalog'),
                'can' => [
                    'orders' => $can('view-orders'), 'review' => $can('review-orders'), 'catalog' => $can('view-catalog'),
                    'manageCatalog' => $can('manage-catalog'), 'users' => $can('manage-users'), 'content' => $can('manage-content'),
                    'reports' => $can('view-reports'), 'schedule' => $can('manage-schedule'), 'integrations' => $can('manage-integrations'),
                ],
            ],
            'poCutoff' => fn () => app(PreorderDate::class)->cutoffLabel(english: true),
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
