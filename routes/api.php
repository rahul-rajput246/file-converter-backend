<?php

use App\Http\Controllers\Api\FileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
|
*/

Route::prefix('files')->group(function () {
    Route::post('/convert', [FileController::class, 'convert'])->name('files.convert');
    Route::post('/compress', [FileController::class, 'compress'])->name('files.compress');
    Route::get('/download/{filename}', [FileController::class, 'download'])->name('files.download');
    Route::get('/supported-formats', [FileController::class, 'supportedFormats'])->name('files.supported-formats');
});
