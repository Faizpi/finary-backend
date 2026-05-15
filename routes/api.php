<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ForumController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TransactionController;
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

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/avatar', [AuthController::class, 'updateAvatar']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'show']);

    Route::prefix('assessment')->group(function () {
        Route::get('/latest', [AssessmentController::class, 'latest']);
        Route::post('/', [AssessmentController::class, 'store'])->middleware('throttle:6,1');
    });

    Route::patch('/assessment/latest', [AssessmentController::class, 'patchLatest']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::put('/transactions/{transaction}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);

    Route::get('/budgets', [BudgetController::class, 'index']);
    Route::post('/budgets', [BudgetController::class, 'store']);
    Route::put('/budgets/{budget}', [BudgetController::class, 'update']);
    Route::delete('/budgets/{budget}', [BudgetController::class, 'destroy']);

    Route::prefix('insights')->group(function () {
        Route::get('/profile', [InsightController::class, 'profile']);
        Route::get('/badges', [InsightController::class, 'badges']);
        Route::get('/leaderboard', [InsightController::class, 'leaderboard']);
    });

    Route::post('/recommendations/side-hustles', [RecommendationController::class, 'sideHustles'])->middleware('throttle:6,1');

    Route::get('/forum/posts', [ForumController::class, 'index']);
    Route::post('/forum/posts', [ForumController::class, 'store']);
    Route::post('/forum/posts/{post}/replies', [ForumController::class, 'reply']);

    Route::get('/reports/transactions/export', [ReportController::class, 'exportTransactions']);
});
