<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertFileRequest;
use App\Http\Requests\CompressFileRequest;
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
