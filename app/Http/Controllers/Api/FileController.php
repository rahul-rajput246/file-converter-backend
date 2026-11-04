<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertFileRequest;
use App\Http\Requests\CompressFileRequest;
use App\Http\Requests\CropFileRequest;
use App\Http\Requests\BatchConvertRequest;
use App\Http\Requests\BatchCompressRequest;
use App\Services\FileProcessingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class FileController extends Controller
{
    /**
     * @var FileProcessingService
     */
    protected FileProcessingService $fileProcessingService;

    /**
     * Inject FileProcessingService.
     */
    public function __construct(FileProcessingService $fileProcessingService)
    {
        $this->fileProcessingService = $fileProcessingService;
    }

    /**
     * POST /api/files/convert
     * Convert an uploaded image to a target format.
     */
    public function convert(ConvertFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $format = $request->input('format');

            $result = $this->fileProcessingService->convertImage($file, $format);

            $isSecure = $request->isSecure() || $request->header('x-forwarded-proto') === 'https' || app()->environment('production');
            $downloadUrl = $isSecure 
                ? secure_url("/api/files/download/{$result['filename']}") 
                : url("/api/files/download/{$result['filename']}");

            return response()->json([
                'success' => true,
                'message' => 'File converted successfully.',
                'filename' => $result['filename'],
                'download_url' => $downloadUrl,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Convert API Failure', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to process the file.',
            ], 500);
        }
    }

    /**
     * POST /api/files/batch-convert
     * Convert up to 10 images in one request with optional ZIP bundle.
     */
    public function batchConvert(BatchConvertRequest $request): JsonResponse
    {
        try {
            $files = $request->file('files');
            $format = $request->input('format');

            $result = $this->fileProcessingService->convertBatch($files, $format, true);

            $isSecure = $request->isSecure() || $request->header('x-forwarded-proto') === 'https' || app()->environment('production');

            // Attach download URLs
            $processedFiles = array_map(function ($item) use ($isSecure) {
                if (!empty($item['filename'])) {
                    $item['download_url'] = $isSecure
                        ? secure_url("/api/files/download/{$item['filename']}")
                        : url("/api/files/download/{$item['filename']}");
                } else {
                    $item['download_url'] = null;
                }
                return $item;
            }, $result['files']);

            $zipDownloadUrl = null;
            if (!empty($result['zip_filename'])) {
                $zipDownloadUrl = $isSecure
                    ? secure_url("/api/files/download/{$result['zip_filename']}")
                    : url("/api/files/download/{$result['zip_filename']}");
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully processed {$result['converted_count']} of {$result['total']} files.",
                'total' => $result['total'],
                'converted_count' => $result['converted_count'],
                'target_format' => $result['target_format'],
                'files' => $processedFiles,
                'zip_filename' => $result['zip_filename'],
                'zip_download_url' => $zipDownloadUrl,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Batch Convert API Failure', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to process the files.',
            ], 500);
        }
    }

    /**
     * POST /api/files/compress
     * Compress an uploaded image using a compression level.
     */
    public function compress(CompressFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $level = $request->input('compression_level');
            
            $targetSizeKb = null;
            if ($request->filled('target_size_kb')) {
                $targetSizeKb = (float) $request->input('target_size_kb');
            } elseif ($request->filled('target_size')) {
                $targetSizeKb = (float) $request->input('target_size');
            }

            $result = $this->fileProcessingService->compressImage($file, $level, $targetSizeKb);

            $isSecure = $request->isSecure() || $request->header('x-forwarded-proto') === 'https' || app()->environment('production');
            $downloadUrl = $isSecure 
                ? secure_url("/api/files/download/{$result['filename']}") 
                : url("/api/files/download/{$result['filename']}");

            return response()->json([
                'success' => true,
                'message' => 'File compressed successfully.',
                'original_size' => $result['original_size'],
                'processed_size' => $result['processed_size'],
                'target_size' => $result['target_size'] ?? null,
                'filename' => $result['filename'],
                'format' => $result['format'] ?? null,
                'compression_level' => $result['compression_level'] ?? $level ?? 'medium',
                'download_url' => $downloadUrl,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Compress API Failure', [
                'error' => $e->getMessage(),
                'payload' => $request->except(['file']),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to process the file.',
            ], 500);
        }
    }

    /**
     * POST /api/files/batch-compress
     * Compress up to 10 images in one request with optional ZIP bundle.
     */
    public function batchCompress(BatchCompressRequest $request): JsonResponse
    {
        try {
            $files = $request->file('files');
            $level = $request->input('compression_level', 'medium');
            $targetSizeKb = $request->filled('target_size_kb') ? (float) $request->input('target_size_kb') : null;

            $result = $this->fileProcessingService->compressBatch($files, $level, $targetSizeKb, true);

            $isSecure = $request->isSecure() || $request->header('x-forwarded-proto') === 'https' || app()->environment('production');

            $processedFiles = array_map(function ($item) use ($isSecure) {
                if (!empty($item['filename'])) {
                    $item['download_url'] = $isSecure
                        ? secure_url("/api/files/download/{$item['filename']}")
                        : url("/api/files/download/{$item['filename']}");
                } else {
                    $item['download_url'] = null;
                }
                return $item;
            }, $result['files']);

            $zipDownloadUrl = null;
            if (!empty($result['zip_filename'])) {
                $zipDownloadUrl = $isSecure
                    ? secure_url("/api/files/download/{$result['zip_filename']}")
                    : url("/api/files/download/{$result['zip_filename']}");
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully compressed {$result['processed_count']} of {$result['total']} files.",
                'total' => $result['total'],
                'processed_count' => $result['processed_count'],
                'files' => $processedFiles,
                'zip_filename' => $result['zip_filename'],
                'zip_download_url' => $zipDownloadUrl,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Batch Compress API Failure', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to compress files.',
            ], 500);
        }
    }

    /**
     * POST /api/files/create-zip
     * Create a ZIP archive from existing converted filenames for 1-click batch download.
     */
    public function createZip(Request $request): JsonResponse
    {
        try {
            $items = $request->input('files', []);
            if (empty($items) || !is_array($items)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No files specified for archive creation.',
                ], 422);
            }

            $zipItems = [];
            foreach ($items as $item) {
                if (is_string($item)) {
                    $zipItems[] = [
                        'filename' => $item,
                        'zip_entry_name' => basename($item),
                    ];
                } elseif (is_array($item) && !empty($item['filename'])) {
                    $zipItems[] = [
                        'filename' => $item['filename'],
                        'zip_entry_name' => $item['original_name'] ?? basename($item['filename']),
                    ];
                }
            }

            $zipFilename = $this->fileProcessingService->createZipArchive($zipItems);

            if (!$zipFilename) {
                return response()->json([
                    'success' => false,
                    'message' => 'ZIP bundle unavailable on server.',
                    'zip_filename' => null,
                    'download_url' => null,
                ], 200);
            }

            $isSecure = $request->isSecure() || $request->header('x-forwarded-proto') === 'https' || app()->environment('production');
            $downloadUrl = $isSecure 
                ? secure_url("/api/files/download/{$zipFilename}") 
                : url("/api/files/download/{$zipFilename}");

            return response()->json([
                'success' => true,
                'zip_filename' => $zipFilename,
                'download_url' => $downloadUrl,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Create Zip API Failure', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'ZIP archive could not be generated.',
                'zip_filename' => null,
                'download_url' => null,
            ], 200);
        }
    }

    /**
     * GET /api/files/download/{filename}
     * Safely download a processed file.
     */
    public function download(string $filename): BinaryFileResponse|JsonResponse
    {
        $filePath = $this->fileProcessingService->getProcessedFilePath($filename);

        if (!$filePath || !file_exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found or link has expired.',
            ], 404);
        }

        $headers = [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return response()->download($filePath, $filename, $headers);
    }

    /**
     * GET /api/files/supported-formats
     * Return list of currently supported conversion and compression formats.
     */
    public function supportedFormats(): JsonResponse
    {
        $formats = config('file_converter.supported_formats', ['jpg', 'jpeg', 'png', 'webp']);

        return response()->json([
            'success' => true,
            'formats' => $formats,
        ], 200);
    }

    /**
     * POST /api/files/crop
     * Crop an uploaded image to specific rectangular coordinates.
     */
    public function crop(CropFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $x = (int) round((float) $request->input('x', 0));
            $y = (int) round((float) $request->input('y', 0));
            $width = (int) round((float) $request->input('width', 100));
            $height = (int) round((float) $request->input('height', 100));
            $format = $request->input('format');
            $quality = $request->filled('quality') ? (int) $request->input('quality') : null;

            $result = $this->fileProcessingService->cropImage($file, $x, $y, $width, $height, $format, $quality);

            $isSecure = $request->isSecure() || $request->header('x-forwarded-proto') === 'https' || app()->environment('production');
            $downloadUrl = $isSecure 
                ? secure_url("/api/files/download/{$result['filename']}") 
                : url("/api/files/download/{$result['filename']}");

            return response()->json([
                'success' => true,
                'message' => 'Image cropped successfully.',
                'filename' => $result['filename'],
                'format' => $result['format'],
                'width' => $result['width'],
                'height' => $result['height'],
                'size' => $result['size'],
                'download_url' => $downloadUrl,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Crop API Failure', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to crop the image.',
            ], 500);
        }
    }

    /**
     * POST /api/files/fetch-url
     * Safely import an image from any public internet URL (bypassing browser CORS).
     */
    public function fetchUrl(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'string', 'url', 'max:2048']
        ]);

        try {
            $url = $request->input('url');
            $data = $this->fileProcessingService->fetchRemoteImage($url);

            return response()->json([
                'success' => true,
                'message' => 'Remote image fetched successfully.',
                'filename' => $data['filename'],
                'mime' => $data['mime'],
                'size' => $data['size'],
                'format' => $data['format'],
                'base64' => $data['base64']
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to fetch image from URL.'
            ], 422);
        }
    }

    /**
     * POST /api/files/strip-exif
     * Server-side metadata scrubber. Strips all EXIF, GPS, and personal device tags.
     */
    public function stripExif(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:102400'], // 100 MB max
            'format' => ['nullable', 'string', 'in:jpg,jpeg,png,webp,gif,avif,bmp'],
            'quality' => ['nullable', 'integer', 'between:10,100']
        ]);

        try {
            $file = $request->file('file');
            $format = $request->input('format');
            $quality = $request->input('quality');

            $result = $this->fileProcessingService->stripExif($file, $format, $quality);

            $isSecure = $request->isSecure() || $request->header('x-forwarded-proto') === 'https' || app()->environment('production');
            $downloadUrl = $isSecure 
                ? secure_url("/api/files/download/{$result['filename']}") 
                : url("/api/files/download/{$result['filename']}");

            return response()->json([
                'success' => true,
                'message' => 'All EXIF and GPS metadata permanently stripped.',
                'filename' => $result['filename'],
                'format' => $result['format'],
                'width' => $result['width'],
                'height' => $result['height'],
                'original_size' => $result['original_size'],
                'processed_size' => $result['processed_size'],
                'download_url' => $downloadUrl
            ], 200);
        } catch (Throwable $e) {
            Log::error('Strip EXIF API Failure', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Failed to strip image metadata.'
            ], 500);
        }
    }

    /**
     * POST /api/files/share-link
     * Create a secure 1-hour expiring share link for any converted/compressed file.
     */
    public function generateShareLink(Request $request): JsonResponse
    {
        $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'original_name' => ['nullable', 'string', 'max:255'],
        ]);

        $filename = basename($request->input('filename'));
        $disk = Storage::disk(config('file_converter.storage.disk', 'file_converter'));

        if (!$disk->exists('processed/' . $filename)) {
            return response()->json([
                'success' => false,
                'message' => 'The requested file could not be found or has already expired.',
            ], 404);
        }

        $size = $disk->size('processed/' . $filename);
        $token = Str::random(32);
        $expiresAt = now()->addHour();

        $shareData = [
            'token' => $token,
            'filename' => $filename,
            'original_name' => $request->input('original_name', $filename),
            'size' => $size,
            'created_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_timestamp' => $expiresAt->timestamp,
        ];

        Cache::put("fileflow_share_{$token}", $shareData, $expiresAt);

        $isSecure = $request->isSecure() || $request->header('x-forwarded-proto') === 'https' || app()->environment('production');
        $downloadUrl = $isSecure 
            ? secure_url("/api/files/download/{$filename}") 
            : url("/api/files/download/{$filename}");

        return response()->json([
            'success' => true,
            'message' => '1-hour expiring share link generated successfully.',
            'token' => $token,
            'share_path' => "/share/{$token}",
            'download_url' => $downloadUrl,
            'original_name' => $shareData['original_name'],
            'size' => $size,
            'expires_at' => $shareData['expires_at'],
            'expires_timestamp' => $shareData['expires_timestamp'],
        ], 200);
    }

    /**
     * GET /api/files/share/{token}
     * Retrieve details and verify an active share token.
     */
    public function getShareDetails(string $token): JsonResponse
    {
        $shareData = Cache::get("fileflow_share_{$token}");

        if (!$shareData) {
            return response()->json([
                'success' => false,
                'message' => 'This share link has expired or is invalid. Files are securely expunged after 1 hour.',
            ], 404);
        }

        $disk = Storage::disk(config('file_converter.storage.disk', 'file_converter'));
        if (!$disk->exists('processed/' . $shareData['filename'])) {
            return response()->json([
                'success' => false,
                'message' => 'This file has already been purged from server memory.',
            ], 404);
        }

        $downloadUrl = request()->isSecure() || request()->header('x-forwarded-proto') === 'https' || app()->environment('production')
            ? secure_url("/api/files/download/{$shareData['filename']}")
            : url("/api/files/download/{$shareData['filename']}");

        return response()->json([
            'success' => true,
            'filename' => $shareData['filename'],
            'original_name' => $shareData['original_name'],
            'size' => $shareData['size'],
            'download_url' => $downloadUrl,
            'created_at' => $shareData['created_at'],
            'expires_at' => $shareData['expires_at'],
            'expires_timestamp' => $shareData['expires_timestamp'],
            'remaining_seconds' => max(0, $shareData['expires_timestamp'] - now()->timestamp),
        ], 200);
    }
}
