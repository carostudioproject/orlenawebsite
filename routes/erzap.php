<?php

use App\Http\Controllers\Webhooks\ErzapWebServiceController;
use App\Http\Middleware\VerifyErzapToken;
use Illuminate\Support\Facades\Route;

// URLs Erzap calls on the website (Erzap API v1.2, section 2). Stateless (no session or CSRF), token in X-Erzap-Token.
Route::prefix('web_service')->middleware(['throttle:erzap-inbound', VerifyErzapToken::class])->group(function () {
    Route::post('/import_produk_json/new', [ErzapWebServiceController::class, 'products']);
    Route::post('/sinkronisasi_stok/new', [ErzapWebServiceController::class, 'stock']);
    Route::post('/sinkronisasi_harga/new', [ErzapWebServiceController::class, 'prices']);
    Route::post('/check_pesanan/new', [ErzapWebServiceController::class, 'orders']);
    Route::post('/update_status_pesanan/new', [ErzapWebServiceController::class, 'orderStatus']);
    Route::post('/sinkronisasi_gambar/new', [ErzapWebServiceController::class, 'images']);
});
