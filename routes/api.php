<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminMockupTemplateController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MockupController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.key')->group(function (): void {
    // Auth
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Products (public)
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/slug/{slug}', [ProductController::class, 'showBySlug']);

    // Products (admin)
    Route::middleware('admin')->group(function (): void {
        Route::get('/products/{id}', [ProductController::class, 'show']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::post('/products/{id}/variants', [ProductController::class, 'addVariant']);
        Route::delete('/products/{id}/variants/{variantId}', [ProductController::class, 'deleteVariant']);
        Route::post('/products/upload-image', [ProductController::class, 'uploadImage']);
    });

    // Orders (customer)
    Route::post('/orders/quote', [OrderController::class, 'quote']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);

    // Mockups (public -- the studio is browsable without an account).
    // Throttled because each miss costs a render.
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::post('/mockups', [MockupController::class, 'store']);
        Route::get('/mockups/{cacheKey}', [MockupController::class, 'show']);
    });

    // Stripe webhook proxy (server-to-server, no user headers required)
    Route::patch('/orders/by-payment-intent/{intentId}', [OrderController::class, 'updateStatusByPaymentIntent']);

    // Orders (admin)
    Route::middleware('admin')->group(function (): void {
        Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    });

    // Admin
    Route::middleware('admin')->prefix('admin')->group(function (): void {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/orders', [AdminController::class, 'orders']);
        Route::get('/orders/{id}', [AdminController::class, 'showOrder']);

        // Mockup templates
        Route::post('/mockup-templates/upload', [AdminMockupTemplateController::class, 'upload']);
        Route::post('/mockup-templates/preview', [AdminMockupTemplateController::class, 'preview']);
        Route::get('/mockup-templates', [AdminMockupTemplateController::class, 'index']);
        Route::post('/mockup-templates', [AdminMockupTemplateController::class, 'store']);
        Route::get('/mockup-templates/{id}', [AdminMockupTemplateController::class, 'show']);
        Route::put('/mockup-templates/{id}', [AdminMockupTemplateController::class, 'update']);
        Route::delete('/mockup-templates/{id}', [AdminMockupTemplateController::class, 'destroy']);
    });
});
