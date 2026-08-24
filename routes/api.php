<?php

use App\Http\Controllers\AuthController;
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

});
