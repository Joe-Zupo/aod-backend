<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamSettingsController;
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
    Route::post('/sessions/{session}/join', [SessionController::class, 'join']);
    Route::post('/sessions/{session}/consent', [SessionController::class, 'consent']);
    Route::post('/sessions/{session}/start', [SessionController::class, 'start']);
    Route::post('/sessions/{session}/complete', [SessionController::class, 'complete']);

});
