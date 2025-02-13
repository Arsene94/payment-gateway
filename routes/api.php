<?php

use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\MockStripeController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api',
], function () {
    Route::post('login/token', [UserController::class, 'createToken'])->name('generate-token');
});


Route::group([
    'middleware' => ['api', 'auth:api'],
], function () {
    Route::post('refresh', [UserController::class, 'refreshToken']);
    Route::post('me', [UserController::class, 'me']);

    // Orders API Endpoints
    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/{id}/pay', [OrderController::class, 'processPayment']);
    Route::post('/mock-stripe/charge', [MockStripeController::class, 'handleWebhook'])->middleware('throttle:10,1')->name('mock-stripe.charge');

    // Transactions API Endpoint
});
