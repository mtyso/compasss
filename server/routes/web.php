<?php

use Illuminate\Support\Facades\Route;

// The Vue app is built into public/ (index.html + assets). Every non-API path
// returns index.html so the client-side router can handle it.
Route::fallback(function () {
    if (request()->is('api/*')) {
        return response()->json(['message' => 'Not found.'], 404);
    }

    $index = public_path('index.html');
    abort_unless(is_file($index), 404);

    return response()->file($index, ['Cache-Control' => 'no-cache']);
});
