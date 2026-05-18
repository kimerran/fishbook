<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Stub for Laravel's Authenticate middleware: it calls route('login') when an unauthed
// non-JSON request hits a guarded route. Without this, the URL generator throws
// RouteNotFoundException *before* AuthenticationException is constructed, producing a 500.
// Defining the name lets the auth exception render normally — handled in bootstrap/app.php.
Route::any('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))
    ->name('login');
