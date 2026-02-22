<?php

use App\Http\Controllers\Api\V1\AuthController;
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
            // TODO: Add member endpoints (dashboard, savings, shares, loans, etc.)
        });

        // ─── Admin routes ────────────────────────────
        Route::middleware('ability:admin')->prefix('admin')->group(function () {
            // TODO: Add admin endpoints (members CRUD, savings, loans, reports, etc.)
        });
    });
});
