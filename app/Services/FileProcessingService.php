<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Laravel\Facades\Image;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FileProcessingService
{
    /**
     * Storage disk instance for file converter files.
     */
    protected function disk()
    {
        return Storage::disk(config('file_converter.storage.disk', 'file_converter'));
    }

    /**
     * Check if a CLI command is accessible on the system PATH.
     */
    public function hasCommand(string $command): bool
    {
        static $cache = [];
        if (isset($cache[$command])) {
            return $cache[$command];
        }

        $cmd = (DIRECTORY_SEPARATOR === '\\')
            ? 'where ' . escapeshellarg($command) . ' 2>nul'
            : 'command -v ' . escapeshellarg($command) . ' 2>/dev/null';

        $output = [];
        $returnVar = 0;
        @exec($cmd, $output, $returnVar);

        $cache[$command] = ($returnVar === 0 && !empty($output));
        return $cache[$command];
    }

    /**
     * Check if FFmpeg CLI is accessible on the system PATH.
     */
    public function hasFfmpeg(): bool
    {
        return $this->hasCommand('ffmpeg');
    }

    /**
     * Determine category of media based on extension and mime type.
     */
    protected function getMediaCategory(string $extension, string $mime): string
    {
        $videoExts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', 'ogv'];
        $audioExts = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a', 'wma'];

        if (in_array($extension, $videoExts, true) || str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if (in_array($extension, $audioExts, true) || str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        return 'image';
    }

    /**
     * Blazing fast raster image conversion using direct native GD.
     * Bypasses heavy framework layers for standard formats (JPG, PNG, WEBP, GIF, BMP).
     */
    protected function convertRasterImageFast(string $inputPath, string $targetFormat, ?string $clientExt = null, ?string $clientMime = null): ?string
    {
        $ext = strtolower($clientExt ?: pathinfo($inputPath, PATHINFO_EXTENSION));
        $targetFormat = strtolower($targetFormat);

        $nativeTargets = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];
        if (!in_array($targetFormat, $nativeTargets, true)) {
            return null;
        }

        $gd = null;

        // Fast load based on extension or mime
        if ($ext === 'jpg' || $ext === 'jpeg' || str_contains($clientMime ?? '', 'jpeg')) {
            $gd = @imagecreatefromjpeg($inputPath);
        } elseif ($ext === 'png' || str_contains($clientMime ?? '', 'png')) {
            $gd = @imagecreatefrompng($inputPath);
        } elseif ($ext === 'webp' || str_contains($clientMime ?? '', 'webp')) {
            $gd = @imagecreatefromwebp($inputPath);
        } elseif ($ext === 'gif' || str_contains($clientMime ?? '', 'gif')) {
            $gd = @imagecreatefromgif($inputPath);
        } elseif ($ext === 'bmp' || str_contains($clientMime ?? '', 'bmp')) {
            $gd = @imagecreatefrombmp($inputPath);
        }

        if (!$gd) {
            $raw = @file_get_contents($inputPath);
            if ($raw) {
                $gd = @imagecreatefromstring($raw);
            }
        }

        if (!$gd) {
            return null;
        }

        $w = imagesx($gd);
        $h = imagesy($gd);

        // Alpha channel handling: only flatten transparent images onto white when saving to JPG
        $isJpgTarget = ($targetFormat === 'jpg' || $targetFormat === 'jpeg');
        $isSourceTransparent = in_array($ext, ['png', 'webp', 'gif'], true) || str_contains($clientMime ?? '', 'png') || str_contains($clientMime ?? '', 'webp');

        if ($isJpgTarget && $isSourceTransparent) {
            $canvas = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
            imagecopy($canvas, $gd, 0, 0, 0, 0, $w, $h);
            imagedestroy($gd);
            $gd = $canvas;
        } elseif (!$isJpgTarget) {
            imagealphablending($gd, false);
            imagesavealpha($gd, true);
        }

        @imageinterlace($gd, false);

        ob_start();
        switch ($targetFormat) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($gd, null, 80);
                break;
            case 'webp':
                imagewebp($gd, null, 75);
                break;
            case 'png':
                imagepng($gd, null, 2);
                break;
            case 'gif':
                imagegif($gd, null);
                break;
            case 'bmp':
                imagebmp($gd, null, true);
                break;
        }
        $data = ob_get_clean();
        imagedestroy($gd);

        return ($data !== false && strlen($data) > 0) ? $data : null;
    }

    /**
     * Convert an image or media file to the requested target format.
     * Supports genuine animated GIF generation from images and videos.
     *
     * @param UploadedFile $file
     * @param string $targetFormat
     * @return array
     * @throws InvalidArgumentException|RuntimeException
     */
    public function convertImage(UploadedFile $file, string $targetFormat): array
    {
        $targetFormat = strtolower(trim($targetFormat));
        $supported = config('file_converter.supported_formats', [
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'ico', 'pdf',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'mp3', 'wav', 'ogg', 'aac'
        ]);

        if (!in_array($targetFormat, $supported, true)) {
            throw new InvalidArgumentException("Unsupported target format: {$targetFormat}");
        }

        $clientExt = strtolower($file->getClientOriginalExtension() ?: '');
        $clientMime = strtolower($file->getMimeType() ?: '');
        $category = $this->getMediaCategory($clientExt, $clientMime);
        $realPath = $file->getRealPath();

        // 1. FAST PATH: Direct native GD conversion for common image-to-image formats (< 30ms)
        if ($category === 'image' && in_array($targetFormat, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true) && $realPath && file_exists($realPath)) {
            $fastData = $this->convertRasterImageFast($realPath, $targetFormat, $clientExt, $clientMime);
            if ($fastData !== null) {
                $outputFilename = $this->generateUniqueFilename('converted', $targetFormat);
                $this->storeProcessedFile($outputFilename, $fastData);

                return [
                    'filename' => $outputFilename,
                    'format' => $targetFormat,
                    'size' => strlen($fastData),
                ];
            }
        }

        // 2. Standard pipeline for PDF, Video, Audio, ICO, AVIF or fallback
        $tempUploadName = $this->generateUniqueFilename('upload', $clientExt ?: 'bin');
        $this->disk()->putFileAs(config('file_converter.storage.uploads', 'uploads'), $file, $tempUploadName);
        $tempRelativePath = config('file_converter.storage.uploads', 'uploads') . '/' . $tempUploadName;
        $tempFullPath = $this->disk()->path($tempRelativePath);

        try {
            $binaryData = null;

            // 2. Handle PDF Document conversion
            if ($clientExt === 'pdf' || $clientMime === 'application/pdf') {
                $binaryData = $this->convertPdf($tempFullPath, $targetFormat);
            }
            // 3. Handle Video or Audio conversion
            else if ($category === 'video' || $category === 'audio') {
                $binaryData = $this->transcodeWithFfmpeg($tempFullPath, $targetFormat, $category);
            } else {
                // 4. Handle Image conversion
                if ($targetFormat === 'mp4' && $this->hasFfmpeg()) {
                    // Image to MP4 video clip
                    $binaryData = $this->transcodeWithFfmpeg($tempFullPath, 'mp4', 'image');
                } else {
                    // Standard clean image format conversion (GIF, PNG, JPG, WebP, AVIF, BMP, ICO, PDF)
                    $image = Image::read($tempFullPath);
                    $binaryData = match ($targetFormat) {
                        'jpg', 'jpeg' => (string) $image->toJpeg(quality: 90),
                        'png' => (string) $image->toPng(),
                        'webp' => (string) $image->toWebp(quality: 90),
                        'gif' => (string) $image->toGif(),
                        'avif' => (string) $image->toAvif(quality: 85),
                        'bmp' => (string) $image->toBmp(),
                        'ico' => $this->transcodeToIco($image),
                        'pdf' => $this->transcodeToPdf($image),
                        default => (string) $image->toJpeg(quality: 90),
                    };
                }
            }

            // 4. Store processed file
            $outputFilename = $this->generateUniqueFilename('converted', $targetFormat);
            $this->storeProcessedFile($outputFilename, $binaryData);

            return [
                'filename' => $outputFilename,
                'format' => $targetFormat,
                'size' => strlen($binaryData),
            ];
        } catch (Throwable $e) {
            Log::error('Conversion error', [
                'category' => $category,
                'target_format' => $targetFormat,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to convert the file: ' . $e->getMessage(), 0, $e);
        } finally {
            // 5. Always clean up temporary upload file
            $this->deleteTemporaryFile($tempRelativePath);
        }
    }

    /**
     * Convert multiple uploaded files (up to max batch limit) to target format.
     * Optionally creates a single ZIP archive containing all converted files.
     *
     * @param array<UploadedFile> $files
     * @param string $targetFormat
     * @param bool $createZip
     * @return array
     */
    public function convertBatch(array $files, string $targetFormat, bool $createZip = true): array
    {
        $maxBatch = (int) config('file_converter.max_batch_files', 10);
        if (count($files) > $maxBatch) {
            $files = array_slice($files, 0, $maxBatch);
        }

        $convertedFiles = [];
        $zipItems = [];

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);

            try {
                $result = $this->convertImage($file, $targetFormat);

                $convertedItem = [
                    'id' => 'item_' . $index . '_' . bin2hex(random_bytes(3)),
                    'original_name' => $originalName,
                    'original_size' => (int) $file->getSize(),
                    'filename' => $result['filename'],
                    'format' => $result['format'],
                    'size' => $result['size'],
                    'success' => true,
                ];

                $convertedFiles[] = $convertedItem;

                $zipItems[] = [
                    'filename' => $result['filename'],
                    'zip_entry_name' => $baseName . '.' . $result['format'],
                ];
            } catch (Throwable $e) {
                Log::warning('Batch conversion item failed', [
                    'file' => $originalName,
                    'error' => $e->getMessage(),
                ]);

                $convertedFiles[] = [
                    'id' => 'item_' . $index . '_' . bin2hex(random_bytes(3)),
                    'original_name' => $originalName,
                    'original_size' => (int) $file->getSize(),
                    'filename' => null,
                    'format' => $targetFormat,
                    'size' => 0,
                    'success' => false,
                    'error' => $e->getMessage() ?: 'Conversion failed.',
                ];
            }
        }

        $zipFilename = null;
        if ($createZip && count($zipItems) > 0 && class_exists('ZipArchive')) {
            $zipFilename = $this->createZipArchive($zipItems);
        }

        return [
            'total' => count($files),
            'converted_count' => count(array_filter($convertedFiles, fn($f) => $f['success'])),
            'target_format' => $targetFormat,
            'files' => $convertedFiles,
            'zip_filename' => $zipFilename,
        ];
    }

    /**
     * Compress multiple uploaded files (up to max batch limit).
     * Optionally creates a single ZIP archive containing all compressed files.
     *
     * @param array<UploadedFile> $files
     * @param string|null $compressionLevel
     * @param float|null $targetSizeKb
     * @param bool $createZip
     * @return array
     */
    public function compressBatch(array $files, ?string $compressionLevel = null, ?float $targetSizeKb = null, bool $createZip = true): array
    {
        $maxBatch = (int) config('file_converter.max_batch_files', 10);
        if (count($files) > $maxBatch) {
            $files = array_slice($files, 0, $maxBatch);
        }

        $compressedFiles = [];
        $zipItems = [];

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);

            try {
                $result = $this->compressImage($file, $compressionLevel, $targetSizeKb);

                $compressedItem = [
                    'id' => 'item_' . $index . '_' . bin2hex(random_bytes(3)),
                    'original_name' => $originalName,
                    'original_size' => $result['original_size'],
                    'processed_size' => $result['processed_size'],
                    'filename' => $result['filename'],
                    'format' => $result['format'],
                    'compression_level' => $result['compression_level'],
                    'success' => true,
                ];

                $compressedFiles[] = $compressedItem;

                $zipItems[] = [
                    'filename' => $result['filename'],
                    'zip_entry_name' => $baseName . '_compressed.' . $result['format'],
                ];
            } catch (Throwable $e) {
                Log::warning('Batch compression item failed', [
                    'file' => $originalName,
                    'error' => $e->getMessage(),
                ]);

                $compressedFiles[] = [
                    'id' => 'item_' . $index . '_' . bin2hex(random_bytes(3)),
                    'original_name' => $originalName,
                    'original_size' => (int) $file->getSize(),
                    'processed_size' => 0,
                    'filename' => null,
                    'format' => null,
                    'success' => false,
                    'error' => $e->getMessage() ?: 'Compression failed.',
                ];
            }
        }

        $zipFilename = null;
        if ($createZip && count($zipItems) > 0 && class_exists('ZipArchive')) {
            $zipFilename = $this->createZipArchive($zipItems);
        }

        return [
            'total' => count($files),
            'processed_count' => count(array_filter($compressedFiles, fn($f) => $f['success'])),
            'files' => $compressedFiles,
            'zip_filename' => $zipFilename,
        ];
    }

    /**
     * Create a ZIP archive from a list of processed files.
     *
     * @param array $zipItems Array of ['filename' => ..., 'zip_entry_name' => ...]
     * @return string|null ZIP filename or null on failure
     */
    public function createZipArchive(array $zipItems): ?string
    {
        if (empty($zipItems)) {
            return null;
        }

        $zipFilename = $this->generateUniqueFilename('batch', 'zip');
        $processedDir = config('file_converter.storage.processed', 'processed');
        $zipFullPath = $this->disk()->path($processedDir . '/' . $zipFilename);

        $dir = dirname($zipFullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Method 1: Native PHP ZipArchive
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipFullPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $usedNames = [];
                $addedCount = 0;
                foreach ($zipItems as $item) {
                    $filePath = $this->disk()->path($processedDir . '/' . $item['filename']);
                    if (file_exists($filePath)) {
                        $entryName = $item['zip_entry_name'] ?? basename($item['filename']);
                        if (isset($usedNames[$entryName])) {
                            $usedNames[$entryName]++;
                            $p = pathinfo($entryName);
                            $entryName = $p['filename'] . '_' . $usedNames[$entryName] . '.' . ($p['extension'] ?? '');
                        } else {
                            $usedNames[$entryName] = 1;
                        }
                        $zip->addFile($filePath, $entryName);
                        $addedCount++;
                    }
                }
                $zip->close();

                if ($addedCount > 0 && file_exists($zipFullPath) && filesize($zipFullPath) > 0) {
                    return $zipFilename;
                }
            }
        }

        // Method 2: System 'zip' CLI utility fallback (available in Linux/Debian/Render containers)
        if ($this->hasCommand('zip')) {
            $fileList = [];
            foreach ($zipItems as $item) {
                $filePath = $this->disk()->path($processedDir . '/' . $item['filename']);
                if (file_exists($filePath)) {
                    $fileList[] = escapeshellarg($filePath);
                }
            }

            if (!empty($fileList)) {
                $filesStr = implode(' ', $fileList);
                $zipTargetEscaped = escapeshellarg($zipFullPath);
                $cmd = "zip -j {$zipTargetEscaped} {$filesStr} 2>&1";
                $output = [];
                $code = 0;
                @exec($cmd, $output, $code);

                if (file_exists($zipFullPath) && filesize($zipFullPath) > 0) {
                    return $zipFilename;
                }
            }
        }

        return null;
    }

    /**
     * Transcode media using FFmpeg with high quality presets.
     */
    protected function transcodeWithFfmpeg(string $inputPath, string $targetFormat, string $category): string
    {
        if (!$this->hasFfmpeg()) {
            throw new RuntimeException("FFmpeg is required to process {$category} conversions. Please ensure FFmpeg is enabled on the server.");
        }

        $tempOut = tempnam(sys_get_temp_dir(), 'ff_out_') . '.' . $targetFormat;
        $inputEscaped = escapeshellarg($inputPath);
        $outEscaped = escapeshellarg($tempOut);

        $cmd = match ($targetFormat) {
            // Video to genuine animated looping GIF using two-pass palette optimization
            'gif' => "ffmpeg -y -i {$inputEscaped} -t 15 -vf \"fps=12,scale='min(480,iw)':-1:flags=lanczos,split[s0][s1];[s0]palettegen=stats_mode=diff[p];[s1][p]paletteuse=dither=bayer:bayer_scale=3\" -loop 0 {$outEscaped} 2>&1",
            
            // Video or Image to MP4 web video
            'mp4' => ($category === 'image')
                ? "ffmpeg -y -loop 1 -i {$inputEscaped} -c:v libx264 -t 3 -pix_fmt yuv420p -vf \"scale=trunc(iw/2)*2:trunc(ih/2)*2\" -movflags +faststart {$outEscaped} 2>&1"
                : "ffmpeg -y -i {$inputEscaped} -c:v libx264 -pix_fmt yuv420p -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart {$outEscaped} 2>&1",
            
            // Video to WebM
            'webm' => "ffmpeg -y -i {$inputEscaped} -c:v libvpx-vp9 -crf 32 -b:v 0 -c:a libopus {$outEscaped} 2>&1",
            
            // Extract or transcode to MP3
            'mp3' => "ffmpeg -y -i {$inputEscaped} -vn -c:a libmp3lame -q:a 2 {$outEscaped} 2>&1",
            
            // Extract or transcode to WAV
            'wav' => "ffmpeg -y -i {$inputEscaped} -vn -c:a pcm_s16le {$outEscaped} 2>&1",
            
            // Extract or transcode to OGG
            'ogg' => "ffmpeg -y -i {$inputEscaped} -vn -c:a libvorbis -q:a 4 {$outEscaped} 2>&1",
            
            // Extract or transcode to AAC
            'aac' => "ffmpeg -y -i {$inputEscaped} -vn -c:a aac -b:a 192k {$outEscaped} 2>&1",
            
            // Video snapshot frame to PNG/JPG
            'png' => "ffmpeg -y -ss 00:00:01 -i {$inputEscaped} -vframes 1 {$outEscaped} 2>&1",
            'jpg', 'jpeg' => "ffmpeg -y -ss 00:00:01 -i {$inputEscaped} -vframes 1 -q:v 2 {$outEscaped} 2>&1",

            default => throw new InvalidArgumentException("No FFmpeg profile configured for: {$targetFormat}"),
        };

        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0 || !file_exists($tempOut) || filesize($tempOut) === 0) {
            @unlink($tempOut);
            Log::error('FFmpeg execution failure', [
                'cmd' => $cmd,
                'output' => implode("\n", array_slice($output, -10)),
                'return_var' => $returnVar,
            ]);
            throw new RuntimeException("Conversion failed during media processing. Please check the source file format.");
        }

        $binary = file_get_contents($tempOut);
        @unlink($tempOut);
        return $binary;
    }

    /**
     * Convert PDF document to images (PNG, JPG, WebP, AVIF, BMP, ICO, GIF) or TXT.
     */
    protected function convertPdf(string $inputPath, string $targetFormat): string
    {
        $targetFormat = strtolower(trim($targetFormat));

        // Case 1: Plain text extraction
        if ($targetFormat === 'txt') {
            return $this->extractTextFromPdf($inputPath);
        }

        $tempDir = sys_get_temp_dir();
        $prefix = $tempDir . DIRECTORY_SEPARATOR . 'pdf_render_' . bin2hex(random_bytes(6));
        $inputEscaped = escapeshellarg($inputPath);
        $renderedFile = null;

        $timeoutPrefix = (DIRECTORY_SEPARATOR !== '\\' && $this->hasCommand('timeout')) ? 'timeout 25 ' : '';

        // Case 2: Primary native renderer: pdftoppm (poppler-utils) - ultra crisp 120 DPI
        if ($this->hasCommand('pdftoppm')) {
            $isJpgTarget = ($targetFormat === 'jpg' || $targetFormat === 'jpeg');
            $formatFlag = $isJpgTarget ? '-jpeg -jpegopt quality=85' : '-png';
            $extSearch = $isJpgTarget ? 'jpg' : 'png';

            $cmd = "{$timeoutPrefix}pdftoppm {$formatFlag} -r 120 -f 1 -l 1 {$inputEscaped} " . escapeshellarg($prefix) . " 2>&1";
            $output = [];
            $code = 0;
            @exec($cmd, $output, $code);

            $matches = glob("{$prefix}*.{$extSearch}");
            if (!empty($matches) && file_exists($matches[0])) {
                $renderedFile = $matches[0];
            }
        }

        // Case 3: Fallback using Ghostscript (gs)
        if (!$renderedFile && $this->hasCommand('gs')) {
            $isJpgTarget = ($targetFormat === 'jpg' || $targetFormat === 'jpeg');
            $device = $isJpgTarget ? 'jpeg' : 'png16m';
            $ext = $isJpgTarget ? 'jpg' : 'png';
            $outPath = $prefix . "-1.{$ext}";
            $outEscaped = escapeshellarg($outPath);
            $cmd = "{$timeoutPrefix}gs -dNOPAUSE -dBATCH -dSAFER -sDEVICE={$device} -r120 -dFirstPage=1 -dLastPage=1 -sOutputFile={$outEscaped} {$inputEscaped} 2>&1";
            $output = [];
            $code = 0;
            @exec($cmd, $output, $code);

            if (file_exists($outPath) && filesize($outPath) > 0) {
                $renderedFile = $outPath;
            }
        }

        // Case 4: Fallback using Imagick PHP extension
        if (!$renderedFile && extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick();
                $imagick->setResolution(150, 150);
                $imagick->readImage($inputPath . '[0]');
                $imagick->setImageFormat('png');
                $outPath = $prefix . '-imagick.png';
                $imagick->writeImage($outPath);
                $imagick->clear();
                $imagick->destroy();

                if (file_exists($outPath) && filesize($outPath) > 0) {
                    $renderedFile = $outPath;
                }
            } catch (Throwable $e) {
                Log::warning('Imagick PDF render fallback error', ['error' => $e->getMessage()]);
            }
        }

        // Case 5: Fallback extract embedded JPEG/DCT stream directly from PDF binary
        if (!$renderedFile) {
            $pdfContent = @file_get_contents($inputPath);
            if ($pdfContent) {
                $startTag = "\xFF\xD8\xFF";
                $endTag = "\xFF\xD9";
                $startPos = strpos($pdfContent, $startTag);
                if ($startPos !== false) {
                    $endPos = strpos($pdfContent, $endTag, $startPos);
                    if ($endPos !== false) {
                        $jpegData = substr($pdfContent, $startPos, $endPos - $startPos + 2);
                        try {
                            $image = Image::read($jpegData);
                            return match ($targetFormat) {
                                'png' => (string) $image->toPng(),
                                'jpg', 'jpeg' => (string) $image->toJpeg(quality: 90),
                                'webp' => (string) $image->toWebp(quality: 90),
                                'gif' => (string) $image->toGif(),
                                'avif' => (string) $image->toAvif(quality: 85),
                                'bmp' => (string) $image->toBmp(),
                                'ico' => $this->transcodeToIco($image),
                                default => (string) $image->toPng(),
                            };
                        } catch (Throwable $t) {
                            // Continue to failure
                        }
                    }
                }
            }
        }

        if (!$renderedFile || !file_exists($renderedFile)) {
            throw new RuntimeException("PDF rendering failed. Server requires poppler-utils (pdftoppm) or Ghostscript to render PDF pages into images.");
        }

        try {
            // Zero-copy fast return: if target is PNG and rendered is PNG, or target is JPG and rendered is JPG
            if ($targetFormat === 'png' && str_ends_with(strtolower($renderedFile), '.png')) {
                return (string) file_get_contents($renderedFile);
            }

            if (($targetFormat === 'jpg' || $targetFormat === 'jpeg') && (str_ends_with(strtolower($renderedFile), '.jpg') || str_ends_with(strtolower($renderedFile), '.jpeg'))) {
                return (string) file_get_contents($renderedFile);
            }

            // Read rendered raster page into Intervention Image for transcoding to WebP, AVIF, GIF, BMP, ICO
            $image = Image::read($renderedFile);

            $binaryData = match ($targetFormat) {
                'png' => (string) $image->toPng(),
                'jpg', 'jpeg' => (string) $image->toJpeg(quality: 90),
                'webp' => (string) $image->toWebp(quality: 90),
                'gif' => (string) $image->toGif(),
                'avif' => (string) $image->toAvif(quality: 85),
                'bmp' => (string) $image->toBmp(),
                'ico' => $this->transcodeToIco($image),
                default => (string) $image->toPng(),
            };

            return $binaryData;
        } finally {
            // Clean up temporary rendered file(s)
            $allMatches = glob("{$prefix}*");
            if (is_array($allMatches)) {
                foreach ($allMatches as $file) {
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }
            }
            if (function_exists('gc_collect_cycles')) {
                @gc_collect_cycles();
            }
        }
    }

    /**
     * Extract plain text content from a PDF document.
     */
    protected function extractTextFromPdf(string $inputPath): string
    {
        $inputEscaped = escapeshellarg($inputPath);
        $tempOut = tempnam(sys_get_temp_dir(), 'pdf_txt_');
        $outEscaped = escapeshellarg($tempOut);

        if ($this->hasCommand('pdftotext')) {
            $cmd = "pdftotext -layout {$inputEscaped} {$outEscaped} 2>&1";
            $output = [];
            $code = 0;
            @exec($cmd, $output, $code);

            if ($code === 0 && file_exists($tempOut) && filesize($tempOut) > 0) {
                $text = file_get_contents($tempOut);
                @unlink($tempOut);
                return $text;
            }
        }

        @unlink($tempOut);

        // Fallback simple stream text extraction
        $raw = @file_get_contents($inputPath);
        $extracted = '';
        if ($raw && preg_match_all('/\((.*?)\)\s*Tj/s', $raw, $matches)) {
            $extracted = implode("\n", $matches[1]);
        }

        return $extracted ?: "PDF text extraction completed.";
    }

    /**
     * Compress a PDF document using Ghostscript pdfwrite.
     *
     * @param string $inputPath
     * @param string $compressionLevel 'low' | 'medium' | 'high'
     * @return string Compressed PDF binary
     */
    protected function compressPdf(string $inputPath, string $compressionLevel = 'medium'): string
    {
        $setting = match ($compressionLevel) {
            'high' => '/screen',     // Maximum compression (~72 DPI)
            'low' => '/printer',     // High quality / light compression (~300 DPI)
            default => '/ebook',     // Balanced compression (~150 DPI)
        };

        if ($this->hasCommand('gs')) {
            $tempOut = tempnam(sys_get_temp_dir(), 'pdf_cmp_') . '.pdf';
            $inputEscaped = escapeshellarg($inputPath);
            $outEscaped = escapeshellarg($tempOut);
            $timeoutPrefix = (DIRECTORY_SEPARATOR !== '\\' && $this->hasCommand('timeout')) ? 'timeout 30 ' : '';

            $cmd = "{$timeoutPrefix}gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS={$setting} -dNOPAUSE -dQUIET -dBATCH -sOutputFile={$outEscaped} {$inputEscaped} 2>&1";
            $output = [];
            $code = 0;
            @exec($cmd, $output, $code);

            if (file_exists($tempOut) && filesize($tempOut) > 0) {
                $compressed = (string) file_get_contents($tempOut);
                @unlink($tempOut);
                return $compressed;
            }
            @unlink($tempOut);
        }

        // Fallback: return original PDF binary
        return (string) file_get_contents($inputPath);
    }

    /**
     * Compress an image or document based on the selected compression level.
     *
     * @param UploadedFile $file
     * @param string|null $compressionLevel 'low' | 'medium' | 'high'
     * @param float|null $targetSizeKb Desired maximum output size in KB
     * @return array
     * @throws InvalidArgumentException|RuntimeException
     */
    public function compressImage(UploadedFile $file, ?string $compressionLevel = null, ?float $targetSizeKb = null): array
    {
        $levels = config('file_converter.compression_levels', []);
        $compressionLevel = $compressionLevel ? strtolower(trim($compressionLevel)) : null;

        // Gracefully default to 'medium' if target size is not given and level is non-standard
        if ((!$targetSizeKb || $targetSizeKb <= 0) && (!$compressionLevel || !array_key_exists($compressionLevel, $levels))) {
            $compressionLevel = 'medium';
        }

        $originalSize = (int) $file->getSize();
        $realPath = $file->getRealPath();
        $mime = (string) $file->getMimeType();
        $clientExt = strtolower($file->getClientOriginalExtension() ?: '');

        // 0. PDF COMPRESSION PATH using Ghostscript pdfwrite
        if ($clientExt === 'pdf' || str_contains($mime, 'pdf')) {
            $sourcePath = ($realPath && file_exists($realPath)) ? $realPath : $file->getPathname();
            $compressedData = $this->compressPdf($sourcePath, $compressionLevel ?? 'medium');
            $outputFilename = $this->generateUniqueFilename('compressed', 'pdf');
            $this->storeProcessedFile($outputFilename, $compressedData);

            return [
                'filename' => $outputFilename,
                'original_size' => $originalSize,
                'processed_size' => strlen($compressedData),
                'target_size' => null,
                'compression_level' => $compressionLevel ?? 'medium',
                'format' => 'pdf',
            ];
        }

        // 1. FAST PATH: Direct GD compression if no complex target size search is requested (< 25ms)
        if ((!$targetSizeKb || $targetSizeKb <= 0) && $realPath && file_exists($realPath)) {
            @ini_set('memory_limit', '512M');
            $format = match (true) {
                str_contains($mime, 'webp') || $clientExt === 'webp' => 'webp',
                str_contains($mime, 'png') || $clientExt === 'png' => 'png',
                default => 'jpg',
            };

            $quality = (int) ($levels[$compressionLevel]['quality'] ?? 50);
            $fastData = null;

            if ($format === 'jpg') {
                $gd = @imagecreatefromjpeg($realPath);
                if (!$gd) {
                    $raw = @file_get_contents($realPath);
                    $gd = $raw ? @imagecreatefromstring($raw) : null;
                }
                if ($gd) {
                    @imageinterlace($gd, false);
                    ob_start();
                    imagejpeg($gd, null, $quality);
                    $fastData = ob_get_clean();
                    imagedestroy($gd);
                }
            } elseif ($format === 'webp') {
                $gd = @imagecreatefromwebp($realPath);
                if (!$gd) {
                    $raw = @file_get_contents($realPath);
                    $gd = $raw ? @imagecreatefromstring($raw) : null;
                }
                if ($gd) {
                    ob_start();
                    imagewebp($gd, null, $quality);
                    $fastData = ob_get_clean();
                    imagedestroy($gd);
                }
            } elseif ($format === 'png') {
                $gd = @imagecreatefrompng($realPath);
                if (!$gd) {
                    $raw = @file_get_contents($realPath);
                    $gd = $raw ? @imagecreatefromstring($raw) : null;
                }
                if ($gd) {
                    imagealphablending($gd, false);
                    imagesavealpha($gd, true);
                    $zlib = match ($compressionLevel) {
                        'low' => 1,
                        'high' => 4,
                        default => 2,
                    };
                    ob_start();
                    imagepng($gd, null, $zlib);
                    $fastData = ob_get_clean();
                    imagedestroy($gd);
                }
            }

            if ($fastData !== null && strlen($fastData) > 0) {
                $outputFilename = $this->generateUniqueFilename('compressed', $format);
                $this->storeProcessedFile($outputFilename, $fastData);

                return [
                    'filename' => $outputFilename,
                    'original_size' => $originalSize,
                    'processed_size' => strlen($fastData),
                    'target_size' => null,
                    'compression_level' => $compressionLevel ?? 'medium',
                    'format' => $format,
                ];
            }
        }

        // 2. Store temporarily in uploads directory for fallback or target-size search
        $tempUploadName = $this->generateUniqueFilename('upload', $file->getClientOriginalExtension() ?: 'bin');
        $this->disk()->putFileAs(config('file_converter.storage.uploads', 'uploads'), $file, $tempUploadName);
        $tempRelativePath = config('file_converter.storage.uploads', 'uploads') . '/' . $tempUploadName;
        $tempFullPath = $this->disk()->path($tempRelativePath);

        try {
            // 2. Determine native format to retain image format during compression
            $mime = $file->getMimeType();
            $clientExt = strtolower($file->getClientOriginalExtension() ?: '');

            $format = match (true) {
                str_contains($mime, 'webp') || $clientExt === 'webp' => 'webp',
                str_contains($mime, 'png') || $clientExt === 'png' => 'png',
                default => 'jpg',
            };

            // 3. Compress using target size if requested, otherwise by preset level
            if ($targetSizeKb !== null && $targetSizeKb > 0) {
                $targetBytes = (int) round($targetSizeKb * 1024);
                $binaryData = $this->compressToTargetSize($tempFullPath, $format, $targetBytes, $originalSize);
            } else {
                $binaryData = match ($format) {
                    'png' => $this->compressPng($tempFullPath, $compressionLevel, $originalSize),
                    'webp' => $this->compressWebp($tempFullPath, $compressionLevel, $originalSize),
                    default => $this->compressJpeg($tempFullPath, $compressionLevel, $originalSize),
                };
            }

            // 4. Store processed file
            $outputFilename = $this->generateUniqueFilename('compressed', $format);
            $this->storeProcessedFile($outputFilename, $binaryData);

            return [
                'filename' => $outputFilename,
                'original_size' => $originalSize,
                'processed_size' => strlen($binaryData),
                'target_size' => $targetSizeKb ? (int) round($targetSizeKb * 1024) : null,
                'compression_level' => $compressionLevel ?? 'target',
                'format' => $format,
            ];
        } catch (Throwable $e) {
            Log::error('Image compression error', [
                'compression_level' => $compressionLevel,
                'target_size_kb' => $targetSizeKb,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to compress the file: ' . $e->getMessage(), 0, $e);
        } finally {
            // Clean up temporary upload
            $this->deleteTemporaryFile($tempRelativePath);
        }
    }

    /**
     * Compress an image to achieve a specific target size in bytes.
     *
     * @param string $filePath
     * @param string $format
     * @param int $targetBytes
     * @param int $originalSize
     * @return string
     */
    protected function compressToTargetSize(string $filePath, string $format, int $targetBytes, int $originalSize): string
    {
        return match ($format) {
            'png' => $this->compressPngToTarget($filePath, $targetBytes, $originalSize),
            'webp' => $this->compressWebpToTarget($filePath, $targetBytes, $originalSize),
            default => $this->compressJpegToTarget($filePath, $targetBytes, $originalSize),
        };
    }

    /**
     * Compress a JPEG to achieve a specific target size.
     *
     * @param string $filePath
     * @param int $targetBytes
     * @param int $originalSize
     * @return string
     */
    protected function compressJpegToTarget(string $filePath, int $targetBytes, int $originalSize): string
    {
        $gd = @imagecreatefromjpeg($filePath);
        if (!$gd) {
            $raw = @file_get_contents($filePath);
            $gd = $raw ? @imagecreatefromstring($raw) : null;
        }

        if (!$gd) {
            return (string) Image::read($filePath)->toJpeg(quality: 50);
        }

        $origW = imagesx($gd);
        $origH = imagesy($gd);
        $bestData = null;
        $bestSize = PHP_INT_MAX;

        // Binary search for highest quality that fits target size
        $low = 10;
        $high = 90;
        while ($low <= $high) {
            $mid = (int) (($low + $high) / 2);
            ob_start();
            imagejpeg($gd, null, $mid);
            $data = ob_get_clean();

            if ($data !== false) {
                $len = strlen($data);
                if ($len <= $targetBytes) {
                    $bestData = $data;
                    $bestSize = $len;
                    $low = $mid + 1; // Try higher quality
                } else {
                    $high = $mid - 1; // Quality too high, step down
                    if ($len < $bestSize) {
                        $bestSize = $len;
                        $bestData = $data;
                    }
                }
            }
        }

        // If even lowest quality exceeds target size, downscale dimensions
        if ($bestSize > $targetBytes && $origW > 60 && $origH > 60) {
            $currentGd = $gd;
            $scale = sqrt($targetBytes / max(1, $bestSize)) * 0.95;
            for ($i = 0; $i < 4 && $bestSize > $targetBytes && $scale < 0.95; $i++) {
                $newW = max(50, (int) ($origW * $scale));
                $newH = max(50, (int) ($origH * $scale));
                $scaledGd = imagescale($currentGd, $newW, $newH);
                if ($scaledGd) {
                    ob_start();
                    imagejpeg($scaledGd, null, 65);
                    $scaledData = ob_get_clean();
                    imagedestroy($scaledGd);

                    if ($scaledData !== false) {
                        $len = strlen($scaledData);
                        if ($len < $bestSize) {
                            $bestSize = $len;
                            $bestData = $scaledData;
                        }
                        if ($len <= $targetBytes) {
                            break;
                        }
                    }
                }
                $scale *= 0.8;
            }
        }

        imagedestroy($gd);

        return $bestData ?? (string) Image::read($filePath)->toJpeg(quality: 30);
    }

    /**
     * Compress a WebP to achieve a specific target size.
     *
     * @param string $filePath
     * @param int $targetBytes
     * @param int $originalSize
     * @return string
     */
    protected function compressWebpToTarget(string $filePath, int $targetBytes, int $originalSize): string
    {
        $gd = @imagecreatefromwebp($filePath);
        if (!$gd) {
            $raw = @file_get_contents($filePath);
            $gd = $raw ? @imagecreatefromstring($raw) : null;
        }

        if (!$gd) {
            return (string) Image::read($filePath)->toWebp(quality: 50);
        }

        $origW = imagesx($gd);
        $origH = imagesy($gd);
        $bestData = null;
        $bestSize = PHP_INT_MAX;

        // Binary search quality
        $low = 10;
        $high = 90;
        while ($low <= $high) {
            $mid = (int) (($low + $high) / 2);
            ob_start();
            imagewebp($gd, null, $mid);
            $data = ob_get_clean();

            if ($data !== false) {
                $len = strlen($data);
                if ($len <= $targetBytes) {
                    $bestData = $data;
                    $bestSize = $len;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                    if ($len < $bestSize) {
                        $bestSize = $len;
                        $bestData = $data;
                    }
                }
            }
        }

        // Downscale if still exceeds target
        if ($bestSize > $targetBytes && $origW > 60 && $origH > 60) {
            $currentGd = $gd;
            $scale = sqrt($targetBytes / max(1, $bestSize)) * 0.95;
            for ($i = 0; $i < 4 && $bestSize > $targetBytes && $scale < 0.95; $i++) {
                $newW = max(50, (int) ($origW * $scale));
                $newH = max(50, (int) ($origH * $scale));
                $scaledGd = imagescale($currentGd, $newW, $newH);
                if ($scaledGd) {
                    ob_start();
                    imagewebp($scaledGd, null, 65);
                    $scaledData = ob_get_clean();
                    imagedestroy($scaledGd);

                    if ($scaledData !== false) {
                        $len = strlen($scaledData);
                        if ($len < $bestSize) {
                            $bestSize = $len;
                            $bestData = $scaledData;
                        }
                        if ($len <= $targetBytes) {
                            break;
                        }
                    }
                }
                $scale *= 0.8;
            }
        }

        imagedestroy($gd);

        return $bestData ?? (string) Image::read($filePath)->toWebp(quality: 30);
    }

    /**
     * Compress a PNG to achieve a specific target size.
     *
     * @param string $filePath
     * @param int $targetBytes
     * @param int $originalSize
     * @return string
     */
    protected function compressPngToTarget(string $filePath, int $targetBytes, int $originalSize): string
    {
        $gd = @imagecreatefrompng($filePath);
        if (!$gd) {
            $raw = @file_get_contents($filePath);
            $gd = $raw ? @imagecreatefromstring($raw) : null;
        }

        if (!$gd) {
            return (string) Image::read($filePath)->toPng();
        }

        $origW = imagesx($gd);
        $origH = imagesy($gd);
        $bestData = null;
        $bestSize = PHP_INT_MAX;

        // Try varying palette color counts
        $colorOptions = [256, 192, 128, 96, 64, 48, 32, 16];
        foreach ($colorOptions as $colors) {
            $testGd = @imagecreatefrompng($filePath);
            if (!$testGd) {
                $raw = @file_get_contents($filePath);
                $testGd = $raw ? @imagecreatefromstring($raw) : null;
            }
            if (!$testGd) continue;

            if (!imageistruecolor($testGd)) {
                imagepalettetotruecolor($testGd);
            }
            imagealphablending($testGd, false);
            imagesavealpha($testGd, true);
            imagetruecolortopalette($testGd, true, $colors);

            ob_start();
            imagepng($testGd, null, 9);
            $data = ob_get_clean();
            imagedestroy($testGd);

            if ($data !== false) {
                $len = strlen($data);
                if ($len < $bestSize) {
                    $bestSize = $len;
                    $bestData = $data;
                }
                if ($len <= $targetBytes) {
                    imagedestroy($gd);
                    return $data;
                }
            }
        }

        // Downscale dimensions if palette reduction alone doesn't reach target size
        if ($bestSize > $targetBytes && $origW > 60 && $origH > 60) {
            $scale = sqrt($targetBytes / max(1, $bestSize)) * 0.95;
            for ($i = 0; $i < 4 && $bestSize > $targetBytes && $scale < 0.95; $i++) {
                $newW = max(50, (int) ($origW * $scale));
                $newH = max(50, (int) ($origH * $scale));
                $scaledGd = imagescale($gd, $newW, $newH);

                if ($scaledGd) {
                    imagealphablending($scaledGd, false);
                    imagesavealpha($scaledGd, true);
                    imagetruecolortopalette($scaledGd, true, 128);

                    ob_start();
                    imagepng($scaledGd, null, 9);
                    $scaledData = ob_get_clean();
                    imagedestroy($scaledGd);

                    if ($scaledData !== false) {
                        $len = strlen($scaledData);
                        if ($len < $bestSize) {
                            $bestSize = $len;
                            $bestData = $scaledData;
                        }
                        if ($len <= $targetBytes) {
                            imagedestroy($gd);
                            return $bestData;
                        }
                    }
                }
                $scale *= 0.8;
            }
        }

        imagedestroy($gd);

        return $bestData ?? (string) Image::read($filePath)->toPng();
    }

    /**
     * Compress a PNG image using adaptive palette quantization and maximum zlib compression.
     *
     * @param string $filePath
     * @param string $compressionLevel
     * @param int $originalSize
     * @return string
     */
    protected function compressPng(string $filePath, string $compressionLevel, int $originalSize): string
    {
        $colorOptions = match ($compressionLevel) {
            'low' => [256, 192, 128],
            'medium' => [128, 96, 64],
            'high' => [64, 48, 32],
            default => [128, 64, 32],
        };

        $bestData = null;
        $bestSize = PHP_INT_MAX;

        foreach ($colorOptions as $colors) {
            $gd = @imagecreatefrompng($filePath);
            if (!$gd) {
                $raw = @file_get_contents($filePath);
                $gd = $raw ? @imagecreatefromstring($raw) : null;
            }

            if (!$gd) {
                break;
            }

            if (!imageistruecolor($gd)) {
                imagepalettetotruecolor($gd);
            }

            imagealphablending($gd, false);
            imagesavealpha($gd, true);
            imagetruecolortopalette($gd, true, $colors);

            ob_start();
            imagepng($gd, null, 9);
            $data = ob_get_clean();
            imagedestroy($gd);

            if ($data !== false && strlen($data) > 0) {
                $currentSize = strlen($data);
                if ($currentSize < $bestSize) {
                    $bestSize = $currentSize;
                    $bestData = $data;
                }

                if ($currentSize < $originalSize) {
                    return $data;
                }
            }
        }

        if ($bestData !== null && $bestSize < $originalSize) {
            return $bestData;
        }

        // Lossless max compression fallback
        $gd = @imagecreatefrompng($filePath);
        if ($gd) {
            imagealphablending($gd, false);
            imagesavealpha($gd, true);
            ob_start();
            imagepng($gd, null, 9);
            $losslessData = ob_get_clean();
            imagedestroy($gd);
            if ($losslessData !== false && strlen($losslessData) < $bestSize) {
                $bestData = $losslessData;
            }
        }

        return $bestData ?? (string) Image::read($filePath)->toPng();
    }

    /**
     * Compress a JPEG image using adaptive quality stepping to guarantee size reduction.
     *
     * @param string $filePath
     * @param string $compressionLevel
     * @param int $originalSize
     * @return string
     */
    protected function compressJpeg(string $filePath, string $compressionLevel, int $originalSize): string
    {
        $levels = config('file_converter.compression_levels', []);
        $targetQuality = (int) ($levels[$compressionLevel]['quality'] ?? 55);

        $gd = @imagecreatefromjpeg($filePath);
        if (!$gd) {
            $raw = @file_get_contents($filePath);
            $gd = $raw ? @imagecreatefromstring($raw) : null;
        }

        if (!$gd) {
            return (string) Image::read($filePath)->toJpeg(quality: $targetQuality);
        }

        // First attempt with target quality
        ob_start();
        imagejpeg($gd, null, $targetQuality);
        $data = ob_get_clean();

        if ($data !== false && strlen($data) < $originalSize) {
            imagedestroy($gd);
            return $data;
        }

        $bestData = $data ?: null;
        $bestSize = $data ? strlen($data) : PHP_INT_MAX;

        // Step down quality if result is still >= original size
        $stepDownMin = match ($compressionLevel) {
            'high' => 15,
            'medium' => 20,
            default => 25,
        };

        for ($q = $targetQuality - 10; $q >= $stepDownMin; $q -= 5) {
            ob_start();
            imagejpeg($gd, null, $q);
            $candidate = ob_get_clean();

            if ($candidate !== false && strlen($candidate) > 0) {
                $len = strlen($candidate);
                if ($len < $bestSize) {
                    $bestSize = $len;
                    $bestData = $candidate;
                }

                if ($len < $originalSize) {
                    imagedestroy($gd);
                    return $candidate;
                }
            }
        }

        imagedestroy($gd);

        return $bestData ?? (string) Image::read($filePath)->toJpeg(quality: $targetQuality);
    }

    /**
     * Compress a WebP image using adaptive quality stepping.
     *
     * @param string $filePath
     * @param string $compressionLevel
     * @param int $originalSize
     * @return string
     */
    protected function compressWebp(string $filePath, string $compressionLevel, int $originalSize): string
    {
        $levels = config('file_converter.compression_levels', []);
        $targetQuality = (int) ($levels[$compressionLevel]['quality'] ?? 55);

        $gd = @imagecreatefromwebp($filePath);
        if (!$gd) {
            $raw = @file_get_contents($filePath);
            $gd = $raw ? @imagecreatefromstring($raw) : null;
        }

        if (!$gd) {
            return (string) Image::read($filePath)->toWebp(quality: $targetQuality);
        }

        ob_start();
        imagewebp($gd, null, $targetQuality);
        $data = ob_get_clean();

        if ($data !== false && strlen($data) < $originalSize) {
            imagedestroy($gd);
            return $data;
        }

        $bestData = $data ?: null;
        $bestSize = $data ? strlen($data) : PHP_INT_MAX;

        $stepDownMin = match ($compressionLevel) {
            'high' => 15,
            'medium' => 20,
            default => 25,
        };

        for ($q = $targetQuality - 10; $q >= $stepDownMin; $q -= 5) {
            ob_start();
            imagewebp($gd, null, $q);
            $candidate = ob_get_clean();

            if ($candidate !== false && strlen($candidate) > 0) {
                $len = strlen($candidate);
                if ($len < $bestSize) {
                    $bestSize = $len;
                    $bestData = $candidate;
                }

                if ($len < $originalSize) {
                    imagedestroy($gd);
                    return $candidate;
                }
            }
        }

        imagedestroy($gd);

        return $bestData ?? (string) Image::read($filePath)->toWebp(quality: $targetQuality);
    }

    /**
     * Generate a cryptographically secure, collision-free filename.
     *
     * @param string $prefix
     * @param string $extension
     * @return string
     */
    public function generateUniqueFilename(string $prefix, string $extension): string
    {
        $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix) ?: 'file';
        $safeExt = ltrim(strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $extension)), '.');
        $random = bin2hex(random_bytes(8));

        return sprintf('%s_%s.%s', $safePrefix, $random, $safeExt);
    }

    /**
     * Store processed file data in the designated storage directory.
     *
     * @param string $filename
     * @param string $binaryData
     * @return string
     */
    public function storeProcessedFile(string $filename, string $binaryData): string
    {
        $processedDir = config('file_converter.storage.processed', 'processed');
        $path = $processedDir . '/' . $filename;
        $this->disk()->put($path, $binaryData);

        return $path;
    }

    /**
     * Safely delete a temporary file if it exists.
     *
     * @param string|null $relativePath
     * @return bool
     */
    public function deleteTemporaryFile(?string $relativePath): bool
    {
        if (empty($relativePath)) {
            return false;
        }

        if ($this->disk()->exists($relativePath)) {
            return $this->disk()->delete($relativePath);
        }

        return false;
    }

    /**
     * Transcode an image into an ICO icon file format (with standard PNG payload).
     *
     * @param \Intervention\Image\Interfaces\ImageInterface $image
     * @return string Binary ICO data
     */
    protected function transcodeToIco($image): string
    {
        $iconImage = clone $image;
        if ($iconImage->width() > 256 || $iconImage->height() > 256) {
            $iconImage->scaleDown(width: 256, height: 256);
        }

        $w = $iconImage->width();
        $h = $iconImage->height();
        $pngData = (string) $iconImage->toPng();
        $pngSize = strlen($pngData);

        // ICO width/height byte is 0 for 256px
        $icoWidth = ($w >= 256) ? 0 : $w;
        $icoHeight = ($h >= 256) ? 0 : $h;

        // ICO Header (6 bytes): Reserved (0), Type (1 = ICO), Image count (1)
        $header = pack('vvv', 0, 1, 1);

        // Directory Entry (16 bytes)
        // Offset is 6 (header) + 16 (1 entry) = 22 bytes
        $entry = pack(
            'CCCCvvVV',
            $icoWidth,  // Width
            $icoHeight, // Height
            0,          // Color count (0 = no palette)
            0,          // Reserved
            1,          // Color planes
            32,         // Bits per pixel
            $pngSize,   // Image data size
            22          // Data offset
        );

        return $header . $entry . $pngData;
    }

    /**
     * Convert an image to a valid standard PDF 1.4 document containing the image.
     *
     * @param \Intervention\Image\Interfaces\ImageInterface $image
     * @return string Binary PDF data
     */
    protected function transcodeToPdf($image): string
    {
        $jpegData = (string) $image->toJpeg(quality: 95);
        $w = $image->width();
        $h = $image->height();
        $jpegLen = strlen($jpegData);

        // Standard PDF points (1 pt = 1/72 inch). Dimensions in points:
        $pw = round($w * 72 / 96, 2);
        $ph = round($h * 72 / 96, 2);

        $out = "%PDF-1.4\n";
        $offsets = [];

        // 1 0 obj: Catalog
        $offsets[1] = strlen($out);
        $out .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // 2 0 obj: Pages
        $offsets[2] = strlen($out);
        $out .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

        // 3 0 obj: Page
        $offsets[3] = strlen($out);
        $out .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] /Contents 4 0 R /Resources << /XObject << /Im1 5 0 R >> /ProcSet [/PDF /ImageC] >> >>\nendobj\n";

        // 4 0 obj: Content stream to paint image
        $content = "q $pw 0 0 $ph 0 0 cm /Im1 Do Q\n";
        $contentLen = strlen($content);

        $offsets[4] = strlen($out);
        $out .= "4 0 obj\n<< /Length $contentLen >>\nstream\n" . $content . "endstream\nendobj\n";

        // 5 0 obj: Image XObject with JPEG stream
        $offsets[5] = strlen($out);
        $out .= "5 0 obj\n<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length $jpegLen >>\nstream\n" . $jpegData . "\nendstream\nendobj\n";

        // xref table
        $xrefOffset = strlen($out);
        $out .= "xref\n0 6\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // trailer
        $out .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF\n";

        return $out;
    }

    /**
     * Retrieve the verified absolute filesystem path of a processed file.
     * Strictly verifies filename pattern and prevents directory traversal attacks.
     *
     * @param string $filename
     * @return string|null
     */
    public function getProcessedFilePath(string $filename): ?string
    {
        if (empty($filename) || !is_string($filename)) {
            return null;
        }

        // 1. Strict regex check on generated format: (converted|compressed|batch)_{hex}.{ext}
        if (!preg_match('/^(converted|compressed|batch)_[a-f0-9]{8,64}\.(jpg|jpeg|png|webp|gif|avif|bmp|ico|pdf|zip|mp4|webm|mov|avi|mkv|mp3|wav|ogg|aac)$/i', $filename)) {
            return null;
        }

        // 2. Prevent basename mismatch or path traversal characters
        if (basename($filename) !== $filename || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return null;
        }

        $processedDir = config('file_converter.storage.processed', 'processed');
        $relativePath = $processedDir . '/' . $filename;

        if (!$this->disk()->exists($relativePath)) {
            return null;
        }

        $baseDir = realpath($this->disk()->path($processedDir));
        $fullPath = realpath($this->disk()->path($relativePath));

        // 3. Confirm path containment inside base processed directory
        if (!$fullPath || !$baseDir || !str_starts_with($fullPath, $baseDir)) {
            return null;
        }

        return $fullPath;
    }

    /**
     * Clean up processed and uploaded files that exceed the configured lifetime.
     *
     * @param int|null $lifetimeMinutes
     * @return int Count of deleted files
     */
    public function cleanExpiredFiles(?int $lifetimeMinutes = null): int
    {
        $lifetimeMinutes = $lifetimeMinutes ?? (int) config('file_converter.file_lifetime', 60);
        $thresholdTimestamp = now()->subMinutes($lifetimeMinutes)->getTimestamp();
        $deletedCount = 0;

        $directories = [
            config('file_converter.storage.processed', 'processed'),
            config('file_converter.storage.uploads', 'uploads'),
        ];

        foreach ($directories as $dir) {
            if (!$this->disk()->exists($dir)) {
                continue;
            }

            $files = $this->disk()->files($dir);

            foreach ($files as $file) {
                try {
                    $lastModified = $this->disk()->lastModified($file);
                    if ($lastModified <= $thresholdTimestamp) {
                        if ($this->disk()->delete($file)) {
                            $deletedCount++;
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('File converter cleanup error on file', [
                        'file' => $file,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $deletedCount;
    }
}
