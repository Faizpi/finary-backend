<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('docs-test');
});

Route::get('/docs', function () {
    return view('docs');
});

Route::get('/docs/openapi.json', function () {
    return response()->file(
        base_path('docs/openapi.json'),
        ['Content-Type' => 'application/json']
    );
});

Route::get('/docs/download', function () {
    return response()->download(
        base_path('docs/API.md'),
        'finary-api.md',
        ['Content-Type' => 'text/markdown']
    );
});

Route::get('/docs/test', function () {
    return redirect('/');
});

// Temporary: clear all caches (remove after confirming fix)
Route::get('/clear-cache', function () {
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    \Illuminate\Support\Facades\Artisan::call('route:clear');
    \Illuminate\Support\Facades\Artisan::call('view:clear');

    return response()->json([
        'message' => 'All caches cleared.',
        'timestamp' => now()->toDateTimeString(),
    ]);
});
