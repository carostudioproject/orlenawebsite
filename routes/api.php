<?php

use App\Http\Controllers\Api\CatalogApiController;
use App\Http\Controllers\Api\OrderApiController;
use App\Http\Middleware\RequireApiToken;
use Illuminate\Support\Facades\Route;

// REST API v1. Documented in docs/rest-api.md.
Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Public read-only catalog, the same data the order form shows.
    Route::get('/categories', [CatalogApiController::class, 'categories']);
    Route::get('/outlets', [CatalogApiController::class, 'outlets']);
    Route::get('/products', [CatalogApiController::class, 'products']);
    Route::get('/schedule', [CatalogApiController::class, 'schedule']);

    Route::middleware(RequireApiToken::class.':api')->group(function () {
        Route::get('/orders', [OrderApiController::class, 'index']);
        Route::get('/orders/{code}', [OrderApiController::class, 'show']);
        Route::get('/reports/sales', [OrderApiController::class, 'report']);
    });

});
