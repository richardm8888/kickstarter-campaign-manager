<?php

use App\Http\Controllers\Api\AdsController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DailyTaskController;
use App\Http\Controllers\Api\LaunchTaskController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\KickstarterFollowerController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Api\LandingPageController;
use App\Http\Controllers\Api\MailerLiteGroupController;
use App\Http\Controllers\Api\PageAnalysisController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\StripeProductController;
use App\Http\Controllers\Api\PublicLandingPageController;
use App\Jobs\ImportLeadsForAllProjects;
use App\Jobs\SyncAllIntegrations;
use App\Jobs\SyncKickstarterFollowers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

// External scheduler hook: lets a GitHub Actions cron (or any scheduler)
// trigger the hourly work on hosts without a resident scheduler.
//
// It has to import contacts as well as metrics. Left to the metrics sync
// alone, a host without a scheduler never pulls Instant Form leads out of
// Facebook, so no welcome email is ever sent — the failure is silent and
// looks like nobody signed up.
Route::post('/internal/run-sync', function (Request $request) {
    $secret = config('app.cron_secret');

    abort_unless(
        is_string($secret) && $secret !== ''
            && hash_equals($secret, (string) $request->header('X-Cron-Secret')),
        403,
    );

    SyncAllIntegrations::dispatch();
    ImportLeadsForAllProjects::dispatch();
    SyncKickstarterFollowers::dispatch();

    return response()->json(['message' => 'Sync dispatched.']);
})->middleware('throttle:10,1');

// Public landing pages
// Says who invited whom to what, so the invite page works before sign-up.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1');

Route::prefix('pages/{slug}')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [PublicLandingPageController::class, 'show']);
    Route::post('/subscribe', [PublicLandingPageController::class, 'subscribe']);
    Route::post('/vip', [PublicLandingPageController::class, 'upgradeToVip']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');

    Route::apiResource('projects', ProjectController::class);

    Route::prefix('projects/{project}')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'show']);

        Route::get('/daily', [DailyTaskController::class, 'index']);
        Route::get('/daily/history', [DailyTaskController::class, 'history']);
        Route::patch('/daily/{task}', [DailyTaskController::class, 'update']);

        Route::get('/seo', [SeoController::class, 'show']);
        // Each call is a paid AI request.
        Route::post('/seo/recommendations', [SeoController::class, 'recommendations'])->middleware('throttle:10,1');

        Route::get('/members', [ProjectMemberController::class, 'index']);
        Route::post('/members', [ProjectMemberController::class, 'invite']);
        Route::delete('/members/{user}', [ProjectMemberController::class, 'remove']);
        Route::delete('/invitations/{invitation}', [ProjectMemberController::class, 'revoke']);

        Route::get('/launch-tasks', [LaunchTaskController::class, 'index']);
        Route::post('/launch-tasks', [LaunchTaskController::class, 'store']);
        Route::post('/launch-tasks/playbook', [LaunchTaskController::class, 'loadPlaybook']);
        Route::post('/launch-tasks/import', [LaunchTaskController::class, 'import']);
        Route::put('/launch-tasks/settings', [LaunchTaskController::class, 'settings']);
        Route::patch('/launch-tasks/{task}', [LaunchTaskController::class, 'update']);
        Route::delete('/launch-tasks/{task}', [LaunchTaskController::class, 'destroy']);
        Route::get('/launch-tasks/{task}/drafts', [LaunchTaskController::class, 'drafts']);
        // Each call is a paid AI request.
        Route::post('/launch-tasks/{task}/drafts', [LaunchTaskController::class, 'generate'])->middleware('throttle:20,1');
        Route::get('/analytics', [AnalyticsController::class, 'show']);
        Route::get('/health', [HealthController::class, 'show']);

        Route::get('/forecast', [ForecastController::class, 'show']);
        Route::put('/forecast/assumptions', [ForecastController::class, 'saveAssumptions']);

        Route::get('/insights', [InsightController::class, 'index']);
        Route::post('/insights/generate', [InsightController::class, 'generate']);
        Route::patch('/insights/{insight}/acknowledge', [InsightController::class, 'acknowledge']);

        Route::get('/integrations', [IntegrationController::class, 'index']);
        Route::get('/integrations/mailerlite/groups', [MailerLiteGroupController::class, 'index']);
        Route::put('/integrations/mailerlite/group', [MailerLiteGroupController::class, 'update']);
        Route::get('/integrations/stripe/products', [StripeProductController::class, 'index']);
        Route::put('/integrations/stripe/products', [StripeProductController::class, 'update']);
        Route::post('/integrations/{provider}/connect', [IntegrationController::class, 'connect']);
        Route::delete('/integrations/{provider}', [IntegrationController::class, 'disconnect']);
        Route::post('/integrations/{provider}/sync', [IntegrationController::class, 'sync']);

        Route::get('/ads', [AdsController::class, 'index']);
        Route::get('/ads/events', [AdsController::class, 'events']);

        Route::get('/page-analyses', [PageAnalysisController::class, 'index']);
        Route::post('/page-analyses', [PageAnalysisController::class, 'store']);

        Route::post('/kickstarter-followers', [KickstarterFollowerController::class, 'store']);

        Route::get('/landing-page', [LandingPageController::class, 'show']);
        Route::patch('/landing-page', [LandingPageController::class, 'update']);
        Route::put('/landing-page/sections', [LandingPageController::class, 'updateSections']);
    });
});
