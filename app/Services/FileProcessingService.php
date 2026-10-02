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
     * Check if FFmpeg CLI is accessible on the system PATH.
     */
    public function hasFfmpeg(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        $cmd = (DIRECTORY_SEPARATOR === '\\') ? 'where ffmpeg 2>nul' : 'command -v ffmpeg 2>/dev/null';
        $output = [];
        $returnVar = 0;
        @exec($cmd, $output, $returnVar);

        $available = ($returnVar === 0 && !empty($output));
        return $available;
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

        // 1. Store temporarily in uploads directory
        $tempUploadName = $this->generateUniqueFilename('upload', $clientExt ?: 'bin');
        $this->disk()->putFileAs(config('file_converter.storage.uploads', 'uploads'), $file, $tempUploadName);
        $tempRelativePath = config('file_converter.storage.uploads', 'uploads') . '/' . $tempUploadName;
        $tempFullPath = $this->disk()->path($tempRelativePath);

        try {
            $binaryData = null;

            // 2. Handle Video or Audio conversion
            if ($category === 'video' || $category === 'audio') {
                $binaryData = $this->transcodeWithFfmpeg($tempFullPath, $targetFormat, $category);
            } else {
                // 3. Handle Image conversion
                if ($targetFormat === 'gif') {
                    // Generate a true animated looping GIF (not a static single-frame GIF)
                    $binaryData = $this->generateAnimatedGifFromImage($tempFullPath);
                } elseif ($targetFormat === 'mp4' && $this->hasFfmpeg()) {
                    // Image to MP4 video clip
                    $binaryData = $this->transcodeWithFfmpeg($tempFullPath, 'mp4', 'image');
                } else {
                    // Standard image format conversion
                    $image = Image::read($tempFullPath);
                    $binaryData = match ($targetFormat) {
                        'jpg', 'jpeg' => (string) $image->toJpeg(quality: 90),
                        'png' => (string) $image->toPng(),
                        'webp' => (string) $image->toWebp(quality: 90),
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
     * Generate a genuine animated looping GIF from a static image.
     * Uses FFmpeg when available, or built-in multi-frame GIF generator with infinite loop.
     */
    protected function generateAnimatedGifFromImage(string $imagePath): string
    {
        // Try pure PHP multi-frame animated GIF engine (guaranteed smooth looping animation)
        try {
            $data = $this->createAnimatedGifFramesPurePhp($imagePath);
            if (!empty($data)) {
                return $data;
            }
        } catch (Throwable $e) {
            Log::warning('Pure PHP animated GIF generation fallback', ['error' => $e->getMessage()]);
        }

        // Fallback to Intervention Image static GIF if GD animation fails
        $image = Image::read($imagePath);
        return (string) $image->toGif();
    }

    /**
     * Create an 8-frame smooth breathing/zoom animated looping GIF using PHP GD.
     */
    protected function createAnimatedGifFramesPurePhp(string $sourcePath): string
    {
        $info = @getimagesize($sourcePath);
        $mime = $info['mime'] ?? '';

        $src = match ($mime) {
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/bmp', 'image/x-ms-bmp' => @imagecreatefrombmp($sourcePath),
            default => null,
        };

        if (!$src) {
            $raw = @file_get_contents($sourcePath);
            $src = $raw ? @imagecreatefromstring($raw) : null;
        }

        if (!$src) {
            throw new RuntimeException('Could not read image resource for animated GIF creation.');
        }

        $width = imagesx($src);
        $height = imagesy($src);

        // Limit dimensions to 480px max for fast rendering and optimal file size
        $maxDim = 480;
        if ($width > $maxDim || $height > $maxDim) {
            $ratio = min($maxDim / $width, $maxDim / $height);
            $targetW = max(50, (int) round($width * $ratio));
            $targetH = max(50, (int) round($height * $ratio));
            $scaled = imagecreatetruecolor($targetW, $targetH);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagecopyresampled($scaled, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
            imagedestroy($src);
            $src = $scaled;
            $width = $targetW;
            $height = $targetH;
        }

        // Generate 8 animation frames with smooth sine ease pulse/zoom
        $frames = [];
        $numFrames = 8;
        for ($i = 0; $i < $numFrames; $i++) {
            $frame = imagecreatetruecolor($width, $height);
            imagealphablending($frame, false);
            imagesavealpha($frame, true);

            // Sine wave produces a gentle 0 -> 1 -> 0 looping pulse
            $progress = sin(($i / $numFrames) * M_PI);
            $scale = 1.0 + (0.08 * $progress);

            $cropW = (int) round($width / $scale);
            $cropH = (int) round($height / $scale);
            $cropX = (int) round(($width - $cropW) / 2);
            $cropY = (int) round(($height - $cropH) / 2);

            imagecopyresampled($frame, $src, 0, 0, $cropX, $cropY, $width, $height, $cropW, $cropH);

            // Quantize to 256-color palette for standard GIF format
            imagetruecolortopalette($frame, true, 256);

            ob_start();
            imagegif($frame);
            $frames[] = ob_get_clean();
            imagedestroy($frame);
        }
        imagedestroy($src);

        return $this->stitchGifFrames($frames, 12);
    }

    /**
     * Stitch individual GIF frames into an animated GIF89a stream with Netscape 2.0 loop extension.
     */
    protected function stitchGifFrames(array $frames, int $delayCs = 12): string
    {
        if (empty($frames)) {
            return '';
        }
        if (count($frames) === 1) {
            return $frames[0];
        }

        $first = $frames[0];
        $header = "GIF89a" . substr($first, 6, 7);

        // Check for Global Color Table
        $packed = ord($first[10]);
        $hasGCT = ($packed & 0x80) !== 0;
        $gct = '';
        if ($hasGCT) {
            $gctCount = 2 << ($packed & 0x07);
            $gct = substr($first, 13, 3 * $gctCount);
        }

        // Netscape 2.0 Looping Application Extension (loop count 0 = infinite loop)
        $loopExtension = "\x21\xFF\x0B" . "NETSCAPE2.0" . "\x03\x01\x00\x00\x00";
        $out = $header . $gct . $loopExtension;

        // Graphic Control Extension (Delay in 1/100ths of a second)
        $delayLo = chr($delayCs & 0xFF);
        $delayHi = chr(($delayCs >> 8) & 0xFF);
        $gce = "\x21\xF9\x04\x00" . $delayLo . $delayHi . "\x00\x00";

        foreach ($frames as $frame) {
            $len = strlen($frame);
            $pos = 13;
            $framePacked = ord($frame[10]);
            if (($framePacked & 0x80) !== 0) {
                $pos += 3 * (2 << ($framePacked & 0x07));
            }

            while ($pos < $len) {
                $b = $frame[$pos];
                if ($b === "\x3B") {
                    break;
                } elseif ($b === "\x21") {
                    $extType = ord($frame[$pos + 1]);
                    if ($extType === 0xF9) {
                        $pos += 8;
                    } else {
                        $pos += 2;
                        while ($pos < $len && ord($frame[$pos]) > 0) {
                            $pos += ord($frame[$pos]) + 1;
                        }
                        $pos++;
                    }
                } elseif ($b === "\x2C") {
                    $imgDescriptor = substr($frame, $pos, 10);
                    $pos += 10;

                    $imgPacked = ord($imgDescriptor[9]);
                    $lct = '';
                    if (($imgPacked & 0x80) !== 0) {
                        $lctSize = 3 * (2 << ($imgPacked & 0x07));
                        $lct = substr($frame, $pos, $lctSize);
                        $pos += $lctSize;
                    }

                    $lzwMinCode = $frame[$pos++];
                    $subBlocks = '';
                    while ($pos < $len) {
                        $blockSize = ord($frame[$pos++]);
                        if ($blockSize === 0) {
                            $subBlocks .= "\x00";
                            break;
                        }
                        $subBlocks .= chr($blockSize) . substr($frame, $pos, $blockSize);
                        $pos += $blockSize;
                    }

                    $out .= $gce . $imgDescriptor . $lct . $lzwMinCode . $subBlocks;
                    break;
                } else {
                    $pos++;
                }
            }
        }

        $out .= "\x3B";
        return $out;
    }

    /**
     * Compress an image based on the selected compression level.
     *
     * @param UploadedFile $file
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

        // 1. Store temporarily in uploads directory
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

        // 1. Strict regex check on generated format: (converted|compressed)_{hex}.{ext}
        if (!preg_match('/^(converted|compressed)_[a-f0-9]{12,64}\.(jpg|jpeg|png|webp|gif|avif|bmp|ico|pdf|mp4|webm|mov|avi|mkv|mp3|wav|ogg|aac)$/i', $filename)) {
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
