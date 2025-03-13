<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CarController;
use App\Http\Controllers\API\RentalController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public car routes
Route::get('/cars', [CarController::class, 'index']);
Route::get('/cars/{car}', [CarController::class, 'show']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // User info
    Route::get('/user', [AuthController::class, 'user']);

    // Rental routes
    Route::apiResource('rentals', RentalController::class);

    // Payment routes
    Route::apiResource('payments', PaymentController::class)->except(['update', 'destroy']);

    // Admin only routes
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('cars', CarController::class)->except(['index', 'show']);
        Route::get('/users', [AuthController::class, 'index']);
    });
});
