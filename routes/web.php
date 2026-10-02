<?php

use Illuminate\Support\Facades\Route;

Route::withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \App\Http\Middleware\EncryptCookies::class,
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \App\Http\Middleware\VerifyCsrfToken::class,
])->get('/', function () {
    return response()->json([
        'status' => 'online',
        'service' => 'FileFlow REST API Backend',
        'version' => '2.0.0',
        'endpoints' => [
            'supported_formats' => url('/api/files/supported-formats'),
            'convert' => url('/api/files/convert'),
            'compress' => url('/api/files/compress'),
            'download' => url('/api/files/download/{filename}'),
        ],
    ]);
});
