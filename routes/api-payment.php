<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentController;

Route::prefix('payment')->name('payment.')->group(function () {
    // Public routes (callbacks)
    Route::post('mpesa/callback', [PaymentController::class, 'mpesaCallback'])->name('mpesa.callback');
    Route::post('yas/callback', [PaymentController::class, 'yasCallback'])->name('yas.callback');
    Route::post('airtel/callback', [PaymentController::class, 'airtelCallback'])->name('airtel.callback');
    Route::post('halotel/callback', [PaymentController::class, 'halotelCallback'])->name('halotel.callback');

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('methods', [PaymentController::class, 'getPaymentMethods'])->name('methods');
        Route::post('initiate-ussd', [PaymentController::class, 'initiateUssd'])->name('initiate-ussd');
        Route::get('check-status', [PaymentController::class, 'checkStatus'])->name('check-status');
        Route::get('history', [PaymentController::class, 'getPaymentHistory'])->name('history');
        Route::get('{transactionRef}', [PaymentController::class, 'getPaymentDetails'])->name('details');
        Route::post('{transactionRef}/retry', [PaymentController::class, 'retryPayment'])->name('retry');
    });
});
