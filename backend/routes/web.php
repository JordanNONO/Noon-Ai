<?php

use App\Http\Controllers\ConnectorController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => config('noon.name'), 'status' => 'ok']));

// Retour de Google après l'autorisation d'un connecteur
Route::get('/oauth/callback', [ConnectorController::class, 'oauthCallback']);
