<?php

use App\Http\Controllers\MockStripeController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', [OrderController::class, 'index'])->name('orders.index');

Route::post('/', [OrderController::class, 'store'])->name('orders.store');

Route::get('/orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
Route::post('/orders/{order}/pay', [OrderController::class, 'paymentStore'])->name('orders.pay.store');
