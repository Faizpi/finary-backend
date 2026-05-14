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

<<<<<<< HEAD
Route::get('/docs/openapi.json', function () {
    return response()->file(
        base_path('docs/openapi.json'),
        ['Content-Type' => 'application/json']
    );
});

=======
>>>>>>> 7bbab614c4c71565bcfb44786a3536493195db2f
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
