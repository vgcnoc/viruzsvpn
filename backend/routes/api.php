<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailSettingController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\VerifyEmailController;
use App\Http\Controllers\Api\VpnAccountController;
use App\Http\Controllers\Api\VpnMonitoringController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,10');
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/password', [AuthController::class, 'updatePassword']);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/billing/orders', [BillingController::class, 'adminOrders']);
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/billing/settings', [BillingController::class, 'settings']);
    Route::put('/billing/settings', [BillingController::class, 'updateSettings']);
    Route::get('/email-settings', [EmailSettingController::class, 'show']);
    Route::put('/email-settings', [EmailSettingController::class, 'update']);
    Route::post('/email-settings/test', [EmailSettingController::class, 'test']);
    Route::post('/billing/orders/{order}/confirm', [BillingController::class, 'confirm']);
    Route::post('/billing/orders/{order}/reject', [BillingController::class, 'reject']);
    Route::get('/dashboard', DashboardController::class);
    Route::get('/monitoring', [VpnMonitoringController::class, 'index']);
    Route::post('/monitoring/accounts/{account}/disconnect', [VpnMonitoringController::class, 'disconnect'])->middleware('throttle:20,1');
    Route::apiResource('servers', ServerController::class);
    Route::post('/servers/{server}/test', [ServerController::class, 'test']);
    Route::get('/servers/{server}/capabilities', [ServerController::class, 'capabilities']);
    Route::get('/servers/{server}/remote-settings', [ServerController::class, 'remoteSettings']);
    Route::apiResource('plans', PlanController::class);
    Route::get('/accounts/export', [VpnAccountController::class, 'export']);
    Route::get('/servers/{server}/provisioning-options', [VpnAccountController::class, 'provisioningOptions']);
    Route::apiResource('accounts', VpnAccountController::class);
    Route::get('/accounts/{account}/client-config', [VpnAccountController::class, 'clientConfiguration']);
    Route::put('/accounts/{account}/remote-access', [VpnAccountController::class, 'updateRemoteAccess']);
    Route::post('/accounts/{account}/suspend', [VpnAccountController::class, 'suspend']);
    Route::post('/accounts/{account}/activate', [VpnAccountController::class, 'activate']);
    Route::post('/accounts/{account}/revoke', [VpnAccountController::class, 'revoke']);
    Route::post('/accounts/{account}/extend', [VpnAccountController::class, 'extend']);
    Route::post('/accounts/{account}/retry', [VpnAccountController::class, 'retry']);
});

Route::middleware(['auth:sanctum', 'customer'])->prefix('customer')->group(function () {
    Route::get('/catalog', [BillingController::class, 'catalog']);
    Route::get('/billing-settings', [BillingController::class, 'settings']);
    Route::get('/orders', [BillingController::class, 'myOrders']);
    Route::post('/orders', [BillingController::class, 'createOrder']);
    Route::post('/orders/{order}/payment', [BillingController::class, 'submitPayment']);
    Route::post('/orders/{order}/cancel', [BillingController::class, 'cancel']);
    Route::get('/accounts', [BillingController::class, 'myAccounts']);
    Route::get('/accounts/{account}/client-config', [VpnAccountController::class, 'clientConfiguration']);
    Route::post('/accounts/{account}/renew', [BillingController::class, 'renew']);
});
