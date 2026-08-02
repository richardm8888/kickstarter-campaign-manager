<?php

use Illuminate\Support\Facades\Route;

/*
 * In a combined deployment the built SPA is copied into public/ and served
 * from here; locally (no build present) the default welcome page shows.
 * Client-side routes like /login fall back to the SPA entry point.
 */
$spa = function () {
    return file_exists(public_path('index.html'))
        ? response()->file(public_path('index.html'))
        : view('welcome');
};

Route::get('/', $spa);

Route::fallback(function () use ($spa) {
    abort_if(request()->is('api/*'), 404);

    return $spa();
});
