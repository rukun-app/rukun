<?php

use Illuminate\Support\Facades\Route;
use Modules\Engagement\Http\EngagementController;

Route::prefix('api/engagement')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    // Teams
    Route::get('teams', [EngagementController::class, 'teams']);
    Route::post('teams', [EngagementController::class, 'createTeam'])->middleware('throttle:admin-sensitive');
    Route::get('teams/{team}/members', [EngagementController::class, 'teamMembers']);
    Route::post('teams/{team}/members', [EngagementController::class, 'updateTeamMember'])->middleware('throttle:admin-sensitive');

    // Patrol policy
    Route::get('patrol-policy/{area}', [EngagementController::class, 'policy']);
    Route::post('patrol-policy', [EngagementController::class, 'setPolicy'])->middleware('throttle:admin-sensitive');

    // Events
    Route::get('events', [EngagementController::class, 'events']);
    Route::get('events/{id}', [EngagementController::class, 'showEvent'])->whereUuid('id');
    Route::post('events', [EngagementController::class, 'createEvent'])->middleware('throttle:admin-sensitive');
    Route::post('events/{id}/cancel', [EngagementController::class, 'cancelEvent'])->whereUuid('id')->middleware('throttle:admin-sensitive');

    // Participants
    Route::get('events/{id}/participants', [EngagementController::class, 'participants'])->whereUuid('id');
    Route::post('events/{id}/participants', [EngagementController::class, 'enroll'])->whereUuid('id')->middleware('throttle:admin-sensitive');
    Route::post('events/{id}/join', [EngagementController::class, 'join'])->whereUuid('id')->middleware('throttle:admin-sensitive');
    Route::post('events/{eventId}/participants/{participantId}/actions', [EngagementController::class, 'participantAction'])->whereUuid(['eventId', 'participantId'])->middleware('throttle:admin-sensitive');
    Route::get('events/{eventId}/participants/{participantId}/history', [EngagementController::class, 'history'])->whereUuid(['eventId', 'participantId']);

    // Incidents
    Route::get('events/{id}/incidents', [EngagementController::class, 'incidents'])->whereUuid('id');
    Route::post('events/{id}/incidents', [EngagementController::class, 'createIncident'])->whereUuid('id')->middleware('throttle:admin-sensitive');
});
