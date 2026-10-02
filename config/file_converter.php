<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Image Formats
    |--------------------------------------------------------------------------
    |
    | Formats initially supported by the file converter and compressor engine.
    |
    */

    'supported_formats' => [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'avif',
        'bmp',
        'ico',
        'pdf',
        'mp4',
        'webm',
        'mov',
        'avi',
        'mkv',
        'mp3',
        'wav',
        'ogg',
        'aac',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported MIME Types
    |--------------------------------------------------------------------------
    |
    | Trusted MIME types allowed during file upload validation.
    |
    */

    'supported_mimes' => [
        // Images
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
        'image/bmp',
        'image/x-ms-bmp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'application/pdf',
        // Videos
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
        'video/mpeg',
        'video/ogg',
        // Audio
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/ogg',
        'audio/aac',
        'audio/x-m4a',
        'audio/flac',
        'application/octet-stream',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max File Upload Size (in Kilobytes)
    |--------------------------------------------------------------------------
    |
    | Default: 102400 KB = 100 MB
    |
    */

    'max_file_size' => (int) env('FILE_CONVERTER_MAX_FILE_SIZE', 102400),

    /*
    |--------------------------------------------------------------------------
    | Processed File Lifetime (in Minutes)
    |--------------------------------------------------------------------------
    |
    | Processed files older than this duration will be cleaned up by the
    | artisan command: php artisan file-converter:cleanup
    |
    */

    'file_lifetime' => (int) env('FILE_CONVERTER_FILE_LIFETIME', 60),

    /*
    |--------------------------------------------------------------------------
    | Storage Directories (Relative to storage/app)
    |--------------------------------------------------------------------------
    */

    'storage' => [
        'disk' => 'file_converter',
        'uploads' => 'uploads',
        'processed' => 'processed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Compression Level Presets
    |--------------------------------------------------------------------------
    |
    | Compression levels mapped to output quality percent (1-100).
    |
    */

    'compression_levels' => [
        'low' => [
            'quality' => 65,
            'description' => 'Light compression, maximum visual fidelity',
        ],
        'medium' => [
            'quality' => 45,
            'description' => 'Balanced compression, good size reduction',
        ],
        'high' => [
            'quality' => 25,
            'description' => 'Aggressive compression, maximum size reduction',
        ],
    ],

];
