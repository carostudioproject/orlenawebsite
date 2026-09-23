<?php

use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\OrderAdditionController;
use App\Http\Controllers\Webhooks\MidtransWebhookController;
use App\Http\Middleware\PrivateOrderResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicPageController::class, 'home'])->name('home');
Route::get('/about', [PublicPageController::class, 'about'])->name('about');
Route::redirect('/our-story', '/about', 301);
Route::get('/blog', [PublicPageController::class, 'blog'])->name('blog.index');
Route::get('/blog/{slug}', [PublicPageController::class, 'article'])->name('blog.show');
Route::get('/sitemap.xml', [PublicPageController::class, 'sitemap']);
Route::get('/robots.txt', [PublicPageController::class, 'robots']);

Route::middleware(PrivateOrderResponse::class)->group(function () {
    Route::get('/order', [CheckoutController::class, 'index'])->block(10, 10);
    Route::post('/order', [CheckoutController::class, 'store'])->middleware('throttle:preorder-submit')->block(10, 10);
    Route::redirect('/products', '/order');
    Route::redirect('/products/{product}', '/order')->whereNumber('product');
    Route::redirect('/cart', '/order');
    Route::redirect('/checkout', '/order');
    Route::get('/orders/{code}', [CheckoutController::class, 'show'])->block(10, 10);
    // Customers add items to their own unpaid order: verify Order Code + WhatsApp, then choose products.
    Route::get('/order/tambah', [OrderAdditionController::class, 'find']);
    Route::post('/order/tambah', [OrderAdditionController::class, 'verify'])->middleware('throttle:preorder-submit');
    Route::get('/orders/{code}/tambah', [OrderAdditionController::class, 'create'])->block(10, 10);
    Route::post('/orders/{code}/tambah', [OrderAdditionController::class, 'store'])->middleware('throttle:preorder-submit')->block(10, 10);
});

Route::post('/webhooks/midtrans', MidtransWebhookController::class)->middleware('throttle:midtrans-webhook');

Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [SessionController::class, 'create'])->name('login');
    Route::post('/admin/login', [SessionController::class, 'store'])->middleware('throttle:staff-login');
});
Route::middleware(['auth', 'active-staff'])->prefix('admin')->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy']);
    Route::get('/', DashboardController::class);
    Route::get('/profile', [ProfileController::class, 'edit']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:staff-login');
    Route::middleware('can:view-orders')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{whatsapp}', [CustomerController::class, 'show']);
        Route::get('/payments', [PaymentController::class, 'index']);
    });
    Route::middleware('can:view-reports')->prefix('/reports')->group(function () {
        Route::get('/', [ReportController::class, 'index']);
        Route::get('/print', [ReportController::class, 'print']);
        Route::get('/pdf', [ReportController::class, 'pdf'])->middleware('throttle:report-export');
        Route::get('/excel', [ReportController::class, 'excel'])->middleware('throttle:report-export');
    });
    Route::middleware('can:manage-schedule')->prefix('/schedule')->group(function () {
        Route::get('/', [ScheduleController::class, 'edit']);
        Route::put('/', [ScheduleController::class, 'update']);
        Route::post('/closed-dates', [ScheduleController::class, 'storeClosedDate']);
        Route::delete('/closed-dates/{closedDate}', [ScheduleController::class, 'destroyClosedDate']);
    });
    Route::middleware('can:manage-integrations')->prefix('/integrations')->group(function () {
        Route::get('/', [IntegrationController::class, 'index']);
        Route::get('/mapping', [IntegrationController::class, 'mapping']);
        Route::put('/mapping', [IntegrationController::class, 'updateMapping']);
        Route::post('/syncs/{sync}/retry', [IntegrationController::class, 'retry'])->middleware('throttle:payment-check');
    });
    Route::middleware('can:review-orders')->prefix('/orders/{order}')->whereNumber('order')->group(function () {
        Route::post('/review', [OrderController::class, 'review']);
        Route::post('/confirm', [OrderController::class, 'confirm']);
        Route::post('/payments/retry', [OrderController::class, 'retryPayment']);
        Route::post('/payments/renew', [OrderController::class, 'renewPayment']);
        Route::post('/payments/{payment}/check', [OrderController::class, 'checkPayment'])->whereNumber('payment')->middleware('throttle:payment-check');
        Route::post('/status', [OrderController::class, 'advance']);
        Route::post('/cancel', [OrderController::class, 'cancel']);
    });
    foreach (['products', 'outlets', 'categories'] as $resource) {
        Route::get('/'.$resource, [CatalogController::class, 'index'])->defaults('resource', $resource)->middleware('can:view-catalog');
        Route::middleware('can:manage-catalog')->group(function () use ($resource) {
            Route::get('/'.$resource.'/create', [CatalogController::class, 'create'])->defaults('resource', $resource);
            Route::post('/'.$resource, [CatalogController::class, 'store'])->defaults('resource', $resource);
            Route::get('/'.$resource.'/{id}/edit', [CatalogController::class, 'edit'])->whereNumber('id')->defaults('resource', $resource);
            Route::put('/'.$resource.'/{id}', [CatalogController::class, 'update'])->whereNumber('id')->defaults('resource', $resource);
            Route::post('/'.$resource.'/{id}/toggle', [CatalogController::class, 'toggle'])->whereNumber('id')->defaults('resource', $resource);
        });
    }
    Route::middleware('can:manage-content')->group(function () {
        Route::get('/content', [ContentController::class, 'index']);
        Route::get('/content/{section}', [ContentController::class, 'edit']);
        Route::post('/content/{section}', [ContentController::class, 'update']);
        Route::post('/content/{section}/reset', [ContentController::class, 'reset']);
        Route::resource('posts', PostController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    });
    Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update'])->middleware('can:manage-users');
});
