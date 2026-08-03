<?php

use App\Http\Controllers\Api\AdsController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\LandingPageController;
use App\Http\Controllers\Api\PageAnalysisController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PublicLandingPageController;
use App\Jobs\SyncAllIntegrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

// External scheduler hook: lets a GitHub Actions cron (or any scheduler)
// trigger the hourly integration sync on hosts without a resident scheduler.
Route::post('/internal/run-sync', function (Request $request) {
    $secret = config('app.cron_secret');

    abort_unless(
        is_string($secret) && $secret !== ''
            && hash_equals($secret, (string) $request->header('X-Cron-Secret')),
        403,
    );

    (new SyncAllIntegrations)->handle();

    return response()->json(['message' => 'Sync dispatched.']);
})->middleware('throttle:10,1');

// Public landing pages
Route::prefix('pages/{slug}')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [PublicLandingPageController::class, 'show']);
    Route::post('/subscribe', [PublicLandingPageController::class, 'subscribe']);
    Route::post('/vip', [PublicLandingPageController::class, 'upgradeToVip']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('projects', ProjectController::class);

    Route::prefix('projects/{project}')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'show']);
        Route::get('/analytics', [AnalyticsController::class, 'show']);
        Route::get('/health', [HealthController::class, 'show']);

        Route::get('/forecast', [ForecastController::class, 'show']);
        Route::post('/forecast/preview', [ForecastController::class, 'preview']);

        Route::get('/insights', [InsightController::class, 'index']);
        Route::post('/insights/generate', [InsightController::class, 'generate']);
        Route::patch('/insights/{insight}/acknowledge', [InsightController::class, 'acknowledge']);

        Route::get('/integrations', [IntegrationController::class, 'index']);
        Route::post('/integrations/{provider}/connect', [IntegrationController::class, 'connect']);
        Route::delete('/integrations/{provider}', [IntegrationController::class, 'disconnect']);
        Route::post('/integrations/{provider}/sync', [IntegrationController::class, 'sync']);

        Route::get('/ads', [AdsController::class, 'index']);
        Route::get('/ads/events', [AdsController::class, 'events']);

        Route::get('/page-analyses', [PageAnalysisController::class, 'index']);
        Route::post('/page-analyses', [PageAnalysisController::class, 'store']);

        Route::get('/landing-page', [LandingPageController::class, 'show']);
        Route::patch('/landing-page', [LandingPageController::class, 'update']);
        Route::put('/landing-page/sections', [LandingPageController::class, 'updateSections']);
    });
});
