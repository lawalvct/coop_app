<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  –  /api/v1/...
|--------------------------------------------------------------------------
|
| All routes in this file are prefixed with /api by the framework.
| We add an additional /v1 prefix for versioning.
|
*/

Route::prefix('v1')->group(function () {

    // ──────────────────────────────────────────────
    //  Public (guest) routes
    // ──────────────────────────────────────────────
    Route::post('/login',    [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

    // Lookup data for registration form
    Route::get('/states',                [AuthController::class, 'states']);
    Route::get('/states/{state}/lgas',   [AuthController::class, 'lgas']);
    Route::get('/faculties',             [AuthController::class, 'faculties']);
    Route::get('/faculties/{faculty}/departments', [AuthController::class, 'departments']);

    // ──────────────────────────────────────────────
    //  Protected routes (require Sanctum token)
    // ──────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Auth / Profile
        Route::get('/me',              [AuthController::class, 'me']);
        Route::post('/logout',         [AuthController::class, 'logout']);
        Route::post('/logout-all',     [AuthController::class, 'logoutAll']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);

        // ─── Member routes ───────────────────────────
        Route::prefix('member')->group(function () {

            // Dashboard
            Route::get('/dashboard', [MemberController::class, 'dashboard']);

            // Savings
            Route::get('/savings',                [MemberController::class, 'savings']);
            Route::get('/savings/monthly-summary', [MemberController::class, 'savingsMonthlySummary']);

            // Savings Settings
            Route::get('/savings-settings',            [MemberController::class, 'savingsSettings']);
            Route::post('/savings-settings',           [MemberController::class, 'storeSavingsSetting']);
            Route::put('/savings-settings/{setting}',  [MemberController::class, 'updateSavingsSetting']);
            Route::delete('/savings-settings/{setting}', [MemberController::class, 'destroySavingsSetting']);

            // Shares
            Route::get('/shares',  [MemberController::class, 'shares']);
            Route::post('/shares', [MemberController::class, 'storeShare']);

            // Loans
            Route::get('/loans',           [MemberController::class, 'loans']);
            Route::post('/loans',          [MemberController::class, 'storeLoan']);
            Route::get('/loans/{loan}',    [MemberController::class, 'showLoan']);
            Route::get('/loan-types',      [MemberController::class, 'loanTypes']);
            Route::post('/loan-calculator', [MemberController::class, 'loanCalculator']);
            Route::get('/members',         [MemberController::class, 'members']);  // For guarantor search

            // Guarantor Requests
            Route::get('/guarantor-requests',              [MemberController::class, 'guarantorRequests']);
            Route::post('/guarantor-requests/{loan}/respond', [MemberController::class, 'respondGuarantorRequest']);

            // Withdrawals
            Route::get('/withdrawals',               [MemberController::class, 'withdrawals']);
            Route::post('/withdrawals',              [MemberController::class, 'storeWithdrawal']);
            Route::get('/withdrawals/{withdrawal}',  [MemberController::class, 'showWithdrawal']);

            // Transactions (Passbook)
            Route::get('/transactions',                [MemberController::class, 'transactions']);
            Route::get('/transactions/{transaction}',  [MemberController::class, 'showTransaction']);

            // Commodities
            Route::get('/commodities',                         [MemberController::class, 'commodities']);
            Route::get('/commodities/{commodity}',             [MemberController::class, 'showCommodity']);
            Route::post('/commodities/{commodity}/subscribe',  [MemberController::class, 'subscribeCommodity']);

            // Commodity Subscriptions & Payments
            Route::get('/commodity-subscriptions',                              [MemberController::class, 'commoditySubscriptions']);
            Route::get('/commodity-subscriptions/{subscription}',               [MemberController::class, 'showCommoditySubscription']);
            Route::post('/commodity-subscriptions/{subscription}/payments',     [MemberController::class, 'storeCommodityPayment']);

            // Notifications
            Route::get('/notifications',                      [MemberController::class, 'notifications']);
            Route::post('/notifications/{notification}/read', [MemberController::class, 'markNotificationRead']);
            Route::post('/notifications/read-all',            [MemberController::class, 'markAllNotificationsRead']);

            // Financial Summary
            Route::get('/financial-summary', [MemberController::class, 'financialSummary']);

            // Profile Update
            Route::put('/profile', [MemberController::class, 'updateProfile']);

            // Resources
            Route::get('/resources',                       [MemberController::class, 'resources']);
            Route::get('/resources/{resource}/download',   [MemberController::class, 'downloadResource']);
        });

        // ─── Admin routes ────────────────────────────
        Route::middleware('ability:admin')->prefix('admin')->group(function () {
            // TODO: Add admin endpoints (members CRUD, savings, loans, reports, etc.)
        });
    });
});
