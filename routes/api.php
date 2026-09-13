<?php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamSettingsController;
use App\Http\Controllers\TimestampController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', [UserController::class, 'show']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', [UserController::class, 'show']);
    Route::put('/me', [UserController::class, 'update']);
    Route::put('/me/password', [UserController::class, 'updatePassword']);
    Route::get('/me/teams', [UserController::class, 'teams']);
    Route::get('/me/membership', [UserController::class, 'membership']);

    // Team dashboard (issue #17). Both act on the caller's active team via
    // resolveTeam(), ?team={id} override, and serve any active member. Only
    // `players` shapes its body by role (coach roster vs player self + median).
    Route::get('/dashboard/header', [DashboardController::class, 'header']);
    Route::get('/dashboard/players', [DashboardController::class, 'players']);

    // Every route below acts on the caller's own active team by default. Pass
    // ?team={id} to act on a different team instead (still subject to the same
    // authorization checks).
    Route::post('/teams/join', [TeamController::class, 'join']);
    Route::get('/teams', [TeamController::class, 'show']);
    Route::put('/teams', [TeamController::class, 'update']);
    Route::delete('/teams/members/{user}', [TeamController::class, 'removeMember']);
    Route::post('/teams/leave', [TeamController::class, 'leave']);
    Route::get('/teams/join-requests', [TeamController::class, 'joinRequests']);
    Route::put('/teams/join-requests/{user}', [TeamController::class, 'decideJoinRequest']);
    Route::get('/teams/settings', [TeamSettingsController::class, 'show']);
    Route::put('/teams/settings', [TeamSettingsController::class, 'update']);
    Route::put('/teams/settings/keywords', [TeamSettingsController::class, 'updateKeywords']);

    // Session routes use explicit route-model binding and authorize directly
    // against {session}'s team via SessionPolicy, deliberately not the
    // resolveTeam()-style "my active team" implicit helper used above.
    Route::get('/teams/{team}/sessions', [SessionController::class, 'index']);
    Route::post('/teams/{team}/sessions', [SessionController::class, 'store']);
    Route::get('/sessions/{session}', [SessionController::class, 'show']);
    Route::get('/sessions/{session}/captions', [SessionController::class, 'captions']);
    Route::get('/sessions/{session}/timeline', [SessionController::class, 'timeline']);
    Route::get('/sessions/{session}/timeline-summary', [SessionController::class, 'timelineSummary']);

    // One endpoint for every coach-driven, bodiless state move; `to` is
    // `cancelled` | `reanalyze` | `analysis_ready` | `timeline_ready`. start and
    // complete are separate (ADR 0010).
    Route::post('/sessions/{session}/transitions', [SessionController::class, 'transition']);

    // Timeline management (docs/adr/0010-timeline-management.md). `{type}` is
    // one of communication_event | game_event | dead_air.
    Route::post('/sessions/{session}/timestamps', [TimestampController::class, 'store']);
    Route::post('/sessions/{session}/timestamps/review-all', [TimestampController::class, 'reviewAll']);
    Route::put('/sessions/{session}/timestamps/{type}/{id}/review', [TimestampController::class, 'review'])
        ->whereIn('type', ['communication_event', 'game_event', 'dead_air']);
    Route::put('/sessions/{session}/timestamps/{type}/{id}', [TimestampController::class, 'update'])
        ->whereIn('type', ['communication_event', 'game_event', 'dead_air']);
    Route::delete('/sessions/{session}/timestamps/{type}/{id}', [TimestampController::class, 'destroy'])
        ->whereIn('type', ['communication_event', 'game_event', 'dead_air']);
    Route::post('/sessions/{session}/timestamps/{type}/{id}/annotations', [AnnotationController::class, 'store'])
        ->whereIn('type', ['communication_event', 'game_event', 'dead_air']);
    Route::put('/annotations/{annotation}', [AnnotationController::class, 'update']);
    Route::delete('/annotations/{annotation}', [AnnotationController::class, 'destroy']);
    Route::post('/annotations/{annotation}/replies', [AnnotationController::class, 'reply']);
    Route::post('/sessions/{session}/join', [SessionController::class, 'join']);
    Route::post('/sessions/{session}/consent', [SessionController::class, 'consent']);
    Route::post('/sessions/{session}/start', [SessionController::class, 'start']);
    Route::post('/sessions/{session}/complete', [SessionController::class, 'complete']);
    Route::post('/sessions/{session}/recording', [SessionController::class, 'uploadRecording']);

});
