<?php

use App\Http\Controllers\Api\V1\AdminController;
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

            // Dashboard
            Route::get('/dashboard', [AdminController::class, 'dashboard']);

            // Lookups (months, years, members dropdown)
            Route::get('/lookup/months',  [AdminController::class, 'lookupMonths']);
            Route::get('/lookup/years',   [AdminController::class, 'lookupYears']);
            Route::get('/lookup/members', [AdminController::class, 'lookupMembers']);

            // ── Members Management ──
            Route::get('/members',                  [AdminController::class, 'members']);
            Route::post('/members',                 [AdminController::class, 'storeMember']);
            Route::get('/members/{member}',         [AdminController::class, 'showMember']);
            Route::put('/members/{member}',         [AdminController::class, 'updateMember']);
            Route::delete('/members/{member}',      [AdminController::class, 'destroyMember']);
            Route::post('/members/{member}/approve',  [AdminController::class, 'approveMember']);
            Route::post('/members/{member}/reject',   [AdminController::class, 'rejectMember']);
            Route::post('/members/{member}/suspend',  [AdminController::class, 'suspendMember']);
            Route::post('/members/{member}/activate', [AdminController::class, 'activateMember']);

            // ── Entrance Fees ──
            Route::get('/entrance-fees',                    [AdminController::class, 'entranceFees']);
            Route::post('/entrance-fees',                   [AdminController::class, 'storeEntranceFee']);
            Route::put('/entrance-fees/{entranceFee}',      [AdminController::class, 'updateEntranceFee']);
            Route::delete('/entrance-fees/{entranceFee}',   [AdminController::class, 'destroyEntranceFee']);

            // ── Saving Types ──
            Route::get('/saving-types',                   [AdminController::class, 'savingTypes']);
            Route::post('/saving-types',                  [AdminController::class, 'storeSavingType']);
            Route::put('/saving-types/{savingType}',      [AdminController::class, 'updateSavingType']);

            // ── Savings ──
            Route::get('/savings',              [AdminController::class, 'savings']);
            Route::post('/savings',             [AdminController::class, 'storeSaving']);
            Route::put('/savings/{saving}',     [AdminController::class, 'updateSaving']);
            Route::delete('/savings/{saving}',  [AdminController::class, 'destroySaving']);

            // ── Savings Settings (approve/reject) ──
            Route::get('/savings-settings',                     [AdminController::class, 'savingsSettings']);
            Route::post('/savings-settings/{setting}/approve',  [AdminController::class, 'approveSavingsSetting']);
            Route::post('/savings-settings/{setting}/reject',   [AdminController::class, 'rejectSavingsSetting']);

            // ── Share Types ──
            Route::get('/share-types',                    [AdminController::class, 'shareTypes']);
            Route::post('/share-types',                   [AdminController::class, 'storeShareType']);
            Route::put('/share-types/{shareType}',        [AdminController::class, 'updateShareType']);
            Route::delete('/share-types/{shareType}',     [AdminController::class, 'destroyShareType']);

            // ── Shares ──
            Route::get('/shares',                         [AdminController::class, 'shares']);
            Route::post('/shares',                        [AdminController::class, 'storeShare']);
            Route::post('/shares/{share}/approve',        [AdminController::class, 'approveShare']);
            Route::post('/shares/{share}/reject',         [AdminController::class, 'rejectShare']);
            Route::delete('/shares/{share}',              [AdminController::class, 'destroyShare']);

            // ── Loan Types ──
            Route::get('/loan-types',                     [AdminController::class, 'loanTypes']);
            Route::post('/loan-types',                    [AdminController::class, 'storeLoanType']);
            Route::put('/loan-types/{loanType}',          [AdminController::class, 'updateLoanType']);
            Route::delete('/loan-types/{loanType}',       [AdminController::class, 'destroyLoanType']);

            // ── Loans ──
            Route::get('/loans',                          [AdminController::class, 'loans']);
            Route::post('/loans',                         [AdminController::class, 'storeLoan']);
            Route::get('/loans/{loan}',                   [AdminController::class, 'showLoan']);
            Route::post('/loans/{loan}/approve',          [AdminController::class, 'approveLoan']);
            Route::post('/loans/{loan}/reject',           [AdminController::class, 'rejectLoan']);

            // ── Loan Repayments ──
            Route::get('/loan-repayments',                        [AdminController::class, 'loanRepayments']);
            Route::post('/loan-repayments/{loan}',                [AdminController::class, 'storeLoanRepayment']);

            // ── Withdrawals ──
            Route::get('/withdrawals',                            [AdminController::class, 'withdrawals']);
            Route::post('/withdrawals',                           [AdminController::class, 'storeWithdrawal']);
            Route::post('/withdrawals/{withdrawal}/approve',      [AdminController::class, 'approveWithdrawal']);
            Route::post('/withdrawals/{withdrawal}/reject',       [AdminController::class, 'rejectWithdrawal']);

            // ── Transactions ──
            Route::get('/transactions',                           [AdminController::class, 'transactions']);
            Route::get('/transactions/{transaction}',             [AdminController::class, 'showTransaction']);
            Route::delete('/transactions/{transaction}',          [AdminController::class, 'destroyTransaction']);

            // ── Commodities ──
            Route::get('/commodities',                            [AdminController::class, 'commodities']);
            Route::post('/commodities',                           [AdminController::class, 'storeCommodity']);
            Route::get('/commodities/{commodity}',                [AdminController::class, 'showCommodity']);
            Route::put('/commodities/{commodity}',                [AdminController::class, 'updateCommodity']);
            Route::delete('/commodities/{commodity}',             [AdminController::class, 'destroyCommodity']);

            // ── Commodity Subscriptions ──
            Route::get('/commodity-subscriptions',                              [AdminController::class, 'commoditySubscriptions']);
            Route::get('/commodity-subscriptions/{subscription}',               [AdminController::class, 'showCommoditySubscription']);
            Route::post('/commodity-subscriptions/{subscription}/approve',      [AdminController::class, 'approveCommoditySubscription']);
            Route::post('/commodity-subscriptions/{subscription}/reject',       [AdminController::class, 'rejectCommoditySubscription']);

            // ── Commodity Payments ──
            Route::get('/commodity-payments',                                   [AdminController::class, 'commodityPayments']);
            Route::post('/commodity-payments/{subscription}',                   [AdminController::class, 'storeCommodityPayment']);
            Route::post('/commodity-payments/{payment}/approve',                [AdminController::class, 'approveCommodityPayment']);
            Route::post('/commodity-payments/{payment}/reject',                 [AdminController::class, 'rejectCommodityPayment']);

            // ── Profile Update Requests ──
            Route::get('/profile-requests',                           [AdminController::class, 'profileUpdateRequests']);
            Route::get('/profile-requests/{profileRequest}',          [AdminController::class, 'showProfileUpdateRequest']);
            Route::post('/profile-requests/{profileRequest}/approve', [AdminController::class, 'approveProfileUpdate']);
            Route::post('/profile-requests/{profileRequest}/reject',  [AdminController::class, 'rejectProfileUpdate']);

            // ── Resources ──
            Route::get('/resources',              [AdminController::class, 'resources']);
            Route::post('/resources',             [AdminController::class, 'storeResource']);
            Route::delete('/resources/{resource}', [AdminController::class, 'destroyResource']);

            // ── FAQs ──
            Route::get('/faqs',           [AdminController::class, 'faqs']);
            Route::post('/faqs',          [AdminController::class, 'storeFaq']);
            Route::put('/faqs/{faq}',     [AdminController::class, 'updateFaq']);
            Route::delete('/faqs/{faq}',  [AdminController::class, 'destroyFaq']);

            // ── Admin Users ──
            Route::get('/admins',     [AdminController::class, 'admins']);
            Route::post('/admins',    [AdminController::class, 'storeAdmin']);

            // ── Roles & Permissions ──
            Route::get('/roles',              [AdminController::class, 'roles']);
            Route::post('/roles',             [AdminController::class, 'storeRole']);
            Route::put('/roles/{role}',       [AdminController::class, 'updateRole']);
            Route::get('/permissions',        [AdminController::class, 'permissions']);

            // ── Financial Summary ──
            Route::get('/financial-summary',            [AdminController::class, 'financialSummary']);
            Route::get('/financial-summary/{member}',   [AdminController::class, 'memberFinancialSummary']);

            // ── Reports ──
            Route::get('/reports/members',              [AdminController::class, 'reportMembers']);
            Route::get('/reports/savings',              [AdminController::class, 'reportSavings']);
            Route::get('/reports/shares',               [AdminController::class, 'reportShares']);
            Route::get('/reports/loans',                [AdminController::class, 'reportLoans']);
            Route::get('/reports/transactions',         [AdminController::class, 'reportTransactions']);
            Route::get('/reports/savings-summary',      [AdminController::class, 'reportSavingsSummary']);
        });
    });
});
