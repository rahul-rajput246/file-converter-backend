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
     * Convert an image to the requested target format.
     *
     * @param UploadedFile $file
     * @param string $targetFormat
     * @return array
     * @throws InvalidArgumentException|RuntimeException
     */
    public function convertImage(UploadedFile $file, string $targetFormat): array
    {
        $targetFormat = strtolower(trim($targetFormat));
        $supported = config('file_converter.supported_formats', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'ico', 'pdf']);

        if (!in_array($targetFormat, $supported, true)) {
            throw new InvalidArgumentException("Unsupported target format: {$targetFormat}");
        }

        // 1. Store temporarily in uploads directory
        $tempUploadName = $this->generateUniqueFilename('upload', $file->getClientOriginalExtension() ?: 'bin');
        $this->disk()->putFileAs(config('file_converter.storage.uploads', 'uploads'), $file, $tempUploadName);
        $tempRelativePath = config('file_converter.storage.uploads', 'uploads') . '/' . $tempUploadName;
        $tempFullPath = $this->disk()->path($tempRelativePath);

        try {
            // 2. Read image through Intervention Image
            $image = Image::read($tempFullPath);

            // 3. Transcode to target format
            $binaryData = match ($targetFormat) {
                'jpg', 'jpeg' => (string) $image->toJpeg(quality: 90),
                'png' => (string) $image->toPng(),
                'webp' => (string) $image->toWebp(quality: 90),
                'gif' => (string) $image->toGif(),
                'avif' => (string) $image->toAvif(quality: 85),
                'bmp' => (string) $image->toBmp(),
                'ico' => $this->transcodeToIco($image),
                'pdf' => $this->transcodeToPdf($image),
                default => throw new InvalidArgumentException("No encoder mapped for: {$targetFormat}"),
            };

            // 4. Store processed file
            $outputFilename = $this->generateUniqueFilename('converted', $targetFormat);
            $this->storeProcessedFile($outputFilename, $binaryData);

            return [
                'filename' => $outputFilename,
                'format' => $targetFormat,
                'size' => strlen($binaryData),
            ];
        } catch (Throwable $e) {
            Log::error('Image conversion error', [
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
        if (!preg_match('/^(converted|compressed)_[a-f0-9]{12,64}\.(jpg|jpeg|png|webp|gif|avif|bmp|ico|pdf)$/i', $filename)) {
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
