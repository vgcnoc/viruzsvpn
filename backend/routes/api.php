<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\VerifyEmailController;
use App\Http\Controllers\Api\VpnAccountController;
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
    Route::get('/dashboard', DashboardController::class);
    Route::apiResource('servers', ServerController::class);
    Route::post('/servers/{server}/test', [ServerController::class, 'test']);
    Route::get('/servers/{server}/capabilities', [ServerController::class, 'capabilities']);
    Route::apiResource('plans', PlanController::class);
    Route::get('/accounts/export', [VpnAccountController::class, 'export']);
    Route::apiResource('accounts', VpnAccountController::class);
    Route::post('/accounts/{account}/suspend', [VpnAccountController::class, 'suspend']);
    Route::post('/accounts/{account}/activate', [VpnAccountController::class, 'activate']);
    Route::post('/accounts/{account}/revoke', [VpnAccountController::class, 'revoke']);
    Route::post('/accounts/{account}/extend', [VpnAccountController::class, 'extend']);
    Route::post('/accounts/{account}/retry', [VpnAccountController::class, 'retry']);
});
