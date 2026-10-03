<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertFileRequest;
use App\Http\Requests\CompressFileRequest;
use App\Http\Requests\BatchConvertRequest;
use App\Http\Requests\BatchCompressRequest;
use App\Services\FileProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
}
