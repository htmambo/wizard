<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OAuthController;

/*
|--------------------------------------------------------------------------
| OAuth Routes
|--------------------------------------------------------------------------
|
| Here are OAuth routes that don't require prefix and CSRF protection.
| These routes use the "api" middleware group.
|
*/

// OAuth v2 token endpoint
Route::post('/oauth/v2/token', [OAuthController::class, 'token']);