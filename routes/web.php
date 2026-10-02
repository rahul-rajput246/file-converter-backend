<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
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
