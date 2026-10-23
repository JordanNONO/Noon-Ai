<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\ComboController;
use App\Http\Controllers\ConnectorController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ModelController;
use App\Http\Controllers\PendingActionController;
use App\Http\Controllers\SpeechController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

// Accès public
Route::get('/auth/options', [AuthController::class, 'options']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateMe']);
    Route::get('/bootstrap', BootstrapController::class);
    Route::get('/languages', [LanguageController::class, 'index']);

    // Conversations
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations/messages', [ConversationController::class, 'send']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send']);
    Route::post('/messages/{message}/regenerate', [ConversationController::class, 'regenerate']);
    Route::post('/messages/{message}/feedback', [ConversationController::class, 'feedback']);
    Route::get('/messages/{message}/audio', [ConversationController::class, 'audio']);
    Route::post('/attachments', [AttachmentController::class, 'store']);

    // Voix (langues bamiléké)
    Route::post('/speech/transcribe', [SpeechController::class, 'transcribe']);
    Route::post('/speech/synthesize', [SpeechController::class, 'synthesize']);

    // Actions en attente d'accord
    Route::post('/pending-actions/{action}/approve', [PendingActionController::class, 'approve']);
    Route::post('/pending-actions/{action}/reject', [PendingActionController::class, 'reject']);

    // Assistants
    Route::get('/agents', [AgentController::class, 'index']);
    Route::post('/agents', [AgentController::class, 'store']);
    Route::get('/agents/{agent}', [AgentController::class, 'show']);
    Route::put('/agents/{agent}', [AgentController::class, 'update']);
    Route::delete('/agents/{agent}', [AgentController::class, 'destroy']);

    // Parcours
    Route::get('/combos', [ComboController::class, 'index']);
    Route::post('/combos', [ComboController::class, 'store']);
    Route::get('/combos/{combo}', [ComboController::class, 'show']);
    Route::put('/combos/{combo}', [ComboController::class, 'update']);
    Route::delete('/combos/{combo}', [ComboController::class, 'destroy']);
    Route::post('/combos/{combo}/favorite', [ComboController::class, 'toggleFavorite']);
    Route::post('/combos/{combo}/run', [ComboController::class, 'run']);
    Route::post('/combo-runs/{run}/resume', [ComboController::class, 'resume']);

    // Bibliothèque des savoirs
    Route::get('/knowledge', [KnowledgeController::class, 'index']);
    Route::post('/knowledge', [KnowledgeController::class, 'store']);
    Route::post('/knowledge/{entry}', [KnowledgeController::class, 'update']); // POST pour accepter un fichier audio
    Route::post('/knowledge/{entry}/review', [KnowledgeController::class, 'review']);
    Route::delete('/knowledge/{entry}', [KnowledgeController::class, 'destroy']);
    Route::get('/knowledge/{entry}/audio', [KnowledgeController::class, 'audio']);

    // Connecteurs et modèles : lecture pour tous
    Route::get('/connectors', [ConnectorController::class, 'index']);
    Route::get('/models', [ModelController::class, 'index']);

    // Administration
    Route::middleware('admin')->group(function () {
        Route::post('/connectors', [ConnectorController::class, 'store']);
        Route::put('/connectors/{connector}', [ConnectorController::class, 'update']);
        Route::post('/connectors/{connector}/connect', [ConnectorController::class, 'connect']);
        Route::post('/connectors/{connector}/disconnect', [ConnectorController::class, 'disconnect']);
        Route::delete('/connectors/{connector}', [ConnectorController::class, 'destroy']);

        Route::post('/models', [ModelController::class, 'store']);
        Route::put('/models/{model}', [ModelController::class, 'update']);
        Route::delete('/models/{model}', [ModelController::class, 'destroy']);
        Route::post('/models/{model}/test', [ModelController::class, 'test']);
        Route::put('/routing-rules/{rule}', [ModelController::class, 'updateRule']);
        Route::put('/settings/fallback', [ModelController::class, 'updateFallback']);

        Route::get('/admin/members', [AdminController::class, 'members']);
        Route::post('/admin/members', [AdminController::class, 'invite']);
        Route::put('/admin/members/{user}', [AdminController::class, 'updateMember']);
        Route::post('/admin/members/{user}/reset-code', [AdminController::class, 'resetCode']);
        Route::get('/admin/groups', [AdminController::class, 'groups']);
        Route::post('/admin/groups', [AdminController::class, 'storeGroup']);
        Route::put('/admin/groups/{group}', [AdminController::class, 'updateGroup']);
        Route::delete('/admin/groups/{group}', [AdminController::class, 'destroyGroup']);
        Route::get('/admin/settings', [AdminController::class, 'settings']);
        Route::put('/admin/settings', [AdminController::class, 'updateSettings']);

        Route::get('/usage', [UsageController::class, 'index']);
        Route::get('/usage/export', [UsageController::class, 'export']);
    });
});
