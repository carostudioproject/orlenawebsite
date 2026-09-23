<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ContentEntry;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    private const ACTIVE = ['confirmed', 'processing', 'ready', 'delivering'];

    public function __invoke(Request $request)
    {
        $user = $request->user();

        // Each block is only computed for roles allowed to see that data.
        return Inertia::render('Admin/Dashboard', [
            'today' => now()->toDateString(),
            'orders' => $user->can('view-orders') ? $this->orders() : null,
            'catalog' => $user->can('view-catalog') ? [
                'products' => Product::count(), 'active_products' => Product::where('is_active', true)->count(),
                'outlets' => Outlet::count(), 'active_outlets' => Outlet::takingPreorders()->count(), 'categories' => Category::count(),
            ] : null,
            'content' => $user->can('manage-content') ? [
                'published_posts' => Post::where('is_published', true)->count(), 'draft_posts' => Post::where('is_published', false)->count(),
                'customized_sections' => ContentEntry::count(),
                'recent_posts' => Post::orderByDesc('updated_at')->limit(5)->get(['id', 'title', 'is_published', 'updated_at']),
            ] : null,
        ]);
    }

    private function orders(): array
    {
        $from = now()->startOfDay()->subDays(13);
        $daily = Order::where('created_at', '>=', $from)->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupBy('day')->pluck('total', 'day');

        return [
            'pending_review' => Order::where('order_status', 'pending_review')->count(),
            'awaiting_payment' => Order::where('order_status', 'confirmed')->where('payment_status', 'pending')->count(),
            'to_process' => Order::whereIn('order_status', self::ACTIVE)->where('payment_status', 'paid')->count(),
            'tomorrow' => Order::whereDate('requested_date', now()->addDay())->where('order_status', '!=', 'cancelled')->count(),
            'paid_this_month' => (int) Payment::where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
            'paid_last_month' => (int) Payment::where('status', 'paid')->whereBetween('paid_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()])->sum('amount'),
            'daily' => collect(range(0, 13))->map(fn ($offset) => [
                'date' => $day = $from->copy()->addDays($offset)->toDateString(), 'total' => (int) ($daily[$day] ?? 0),
            ]),
            'payment_breakdown' => Order::where('order_status', '!=', 'cancelled')->selectRaw('payment_status, COUNT(*) as total')->groupBy('payment_status')->pluck('total', 'payment_status'),
            // Work that needs a person: new requests to review, and paid orders to prepare, soonest first.
            'needs_action' => Order::with('customer:id,name')
                ->where(fn ($q) => $q->where('order_status', 'pending_review')->orWhere(fn ($q) => $q->whereIn('order_status', self::ACTIVE)->where('payment_status', 'paid')))
                ->orderBy('requested_date')->orderBy('id')->limit(6)
                ->get(['id', 'order_code', 'customer_id', 'requested_date', 'total', 'order_status', 'payment_status']),
        ];
    }
}
