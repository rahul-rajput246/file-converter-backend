<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileConverterApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Use fake storage for tests to isolate filesystem side-effects
        Storage::fake('file_converter');
    }

    /**
     * Conversion Tests:
     * - PNG -> JPG
     * - PNG -> WEBP
     * - JPG -> PNG
     * - JPG -> WEBP
     * - WEBP -> JPG
     * - WEBP -> PNG
     */
    public function test_can_convert_png_to_jpg(): void
    {
        $file = UploadedFile::fake()->image('sample.png', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'jpg',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'filename',
                'download_url',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.jpg', $filename);
        $this->assertStringStartsWith('converted_', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);

        // Verify the file can be downloaded
        $downloadResponse = $this->get('/api/files/download/' . $filename);
        $downloadResponse->assertStatus(200);
    }

    public function test_can_convert_png_to_webp(): void
    {
        $file = UploadedFile::fake()->image('sample.png', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'webp',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.webp', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_convert_jpg_to_png(): void
    {
        $file = UploadedFile::fake()->image('sample.jpg', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'png',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.png', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_convert_jpg_to_webp(): void
    {
        $file = UploadedFile::fake()->image('sample.jpg', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'webp',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.webp', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_convert_webp_to_jpg(): void
    {
        $file = UploadedFile::fake()->image('sample.webp', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'jpg',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.jpg', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_convert_webp_to_png(): void
    {
        $file = UploadedFile::fake()->image('sample.webp', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'png',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.png', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_convert_jpg_to_gif(): void
    {
        $file = UploadedFile::fake()->image('sample.jpg', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'gif',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.gif', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_convert_jpg_to_avif(): void
    {
        $file = UploadedFile::fake()->image('sample.jpg', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'avif',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.avif', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_convert_jpg_to_bmp(): void
    {
        $file = UploadedFile::fake()->image('sample.jpg', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'bmp',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.bmp', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_convert_jpg_to_ico(): void
    {
        $file = UploadedFile::fake()->image('sample.jpg', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'ico',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.ico', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);

        $downloadResponse = $this->get('/api/files/download/' . $filename);
        $downloadResponse->assertStatus(200);
    }

    public function test_can_convert_jpg_to_pdf(): void
    {
        $file = UploadedFile::fake()->image('sample.jpg', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'pdf',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File converted successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringEndsWith('.pdf', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);

        $downloadResponse = $this->get('/api/files/download/' . $filename);
        $downloadResponse->assertStatus(200);
    }

    /**
     * Compression Tests:
     * - low
     * - medium
     * - high
     */
    public function test_can_compress_image_with_low_level(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $response = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'low',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File compressed successfully.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'original_size',
                'processed_size',
                'filename',
                'download_url',
            ]);

        $this->assertGreaterThan(0, $response->json('original_size'));
        $this->assertGreaterThan(0, $response->json('processed_size'));

        $filename = $response->json('filename');
        $this->assertStringStartsWith('compressed_', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_compress_image_with_medium_level(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 100, 100);

        $response = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'medium',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File compressed successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringStartsWith('compressed_', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_compress_image_with_high_level(): void
    {
        $file = UploadedFile::fake()->image('photo.webp', 100, 100);

        $response = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'high',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File compressed successfully.',
            ]);

        $filename = $response->json('filename');
        $this->assertStringStartsWith('compressed_', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_compress_gif_and_preserves_gif_format(): void
    {
        // Create an uncompressed sample GIF
        $tempPath = tempnam(sys_get_temp_dir(), 'test_gif') . '.gif';
        $gd = imagecreatetruecolor(80, 80);
        $color = imagecolorallocate($gd, 255, 100, 50);
        imagefill($gd, 0, 0, $color);
        imagegif($gd, $tempPath);
        imagedestroy($gd);

        $file = new UploadedFile($tempPath, 'animation.gif', 'image/gif', null, true);

        $response = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'medium',
        ]);

        @unlink($tempPath);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File compressed successfully.',
                'format' => 'gif',
            ]);

        $filename = $response->json('filename');
        $this->assertStringStartsWith('compressed_', $filename);
        $this->assertStringEndsWith('.gif', $filename);
        $this->assertStringNotContainsString('.jpg', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_can_compress_bmp_and_preserves_bmp_format(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_bmp') . '.bmp';
        $gd = imagecreatetruecolor(60, 60);
        $color = imagecolorallocate($gd, 10, 150, 200);
        imagefill($gd, 0, 0, $color);
        imagebmp($gd, $tempPath);
        imagedestroy($gd);

        $file = new UploadedFile($tempPath, 'graphic.bmp', 'image/bmp', null, true);

        $response = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'medium',
        ]);

        @unlink($tempPath);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File compressed successfully.',
                'format' => 'bmp',
            ]);

        $filename = $response->json('filename');
        $this->assertStringStartsWith('compressed_', $filename);
        $this->assertStringEndsWith('.bmp', $filename);
        Storage::disk('file_converter')->assertExists('processed/' . $filename);
    }

    public function test_batch_compress_preserves_respective_formats(): void
    {
        $gifTemp = tempnam(sys_get_temp_dir(), 'batch_gif') . '.gif';
        $gdGif = imagecreatetruecolor(50, 50);
        imagefill($gdGif, 0, 0, imagecolorallocate($gdGif, 200, 50, 50));
        imagegif($gdGif, $gifTemp);
        imagedestroy($gdGif);

        $gifFile = new UploadedFile($gifTemp, 'sample.gif', 'image/gif', null, true);
        $pngFile = UploadedFile::fake()->image('sample.png', 50, 50);

        $response = $this->postJson('/api/files/batch-compress', [
            'files' => [$gifFile, $pngFile],
            'compression_level' => 'medium',
        ]);

        @unlink($gifTemp);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'total' => 2,
                'processed_count' => 2,
            ]);

        $files = $response->json('files');
        $this->assertCount(2, $files);
        $this->assertEquals('gif', $files[0]['format']);
        $this->assertStringEndsWith('.gif', $files[0]['filename']);
        $this->assertEquals('png', $files[1]['format']);
        $this->assertStringEndsWith('.png', $files[1]['filename']);
    }

    public function test_compression_substantially_reduces_file_size(): void
    {
        // Generate a truecolor image with rich gradient to simulate real photo
        $tempPath = tempnam(sys_get_temp_dir(), 'test_img') . '.png';
        $gd = imagecreatetruecolor(300, 300);
        for ($x = 0; $x < 300; $x++) {
            for ($y = 0; $y < 300; $y++) {
                $color = imagecolorallocate($gd, ($x * 2) % 256, ($y * 2) % 256, ($x + $y) % 256);
                imagesetpixel($gd, $x, $y, $color);
            }
        }
        imagepng($gd, $tempPath, 0); // Uncompressed raw PNG to simulate camera/screenshot
        imagedestroy($gd);

        $originalSize = filesize($tempPath);
        $file = new UploadedFile($tempPath, 'gradient.png', 'image/png', null, true);

        $responseLow = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'low',
        ]);

        $responseHigh = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'high',
        ]);

        @unlink($tempPath);

        $responseLow->assertStatus(200);
        $responseHigh->assertStatus(200);

        $lowSize = $responseLow->json('processed_size');
        $highSize = $responseHigh->json('processed_size');

        $this->assertLessThan($originalSize, $lowSize, 'Low compression should produce smaller size than original');
        $this->assertLessThan($originalSize, $highSize, 'High compression should produce smaller size than original');
        $this->assertLessThanOrEqual($lowSize, $highSize, 'High compression should produce smaller or equal size compared to low');
    }

    public function test_can_compress_to_specific_target_size_kb(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_target') . '.jpg';
        $gd = imagecreatetruecolor(400, 400);
        for ($x = 0; $x < 400; $x++) {
            for ($y = 0; $y < 400; $y++) {
                $color = imagecolorallocate($gd, ($x * 3) % 256, ($y * 3) % 256, ($x + $y) % 256);
                imagesetpixel($gd, $x, $y, $color);
            }
        }
        imagejpeg($gd, $tempPath, 95);
        imagedestroy($gd);

        $file = new UploadedFile($tempPath, 'highres.jpg', 'image/jpeg', null, true);

        // Request target size of 20 KB
        $response = $this->postJson('/api/files/compress', [
            'file' => $file,
            'target_size_kb' => 20,
        ]);

        @unlink($tempPath);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File compressed successfully.',
            ]);

        $processedSize = $response->json('processed_size');
        // Assert processed size is <= 20 KB (with a small 5% buffer if needed)
        $this->assertLessThanOrEqual(20 * 1024 * 1.05, $processedSize);

        $filename = $response->json('filename');
        $processedPath = Storage::disk('file_converter')->path('processed/' . $filename);
        $size = getimagesize($processedPath);
        $this->assertEquals(400, $size[0], 'Target-size compression must retain 400px width');
        $this->assertEquals(400, $size[1], 'Target-size compression must retain 400px height');
    }

    public function test_compression_preserves_original_dimensions_without_downscaling_or_blurring(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_dim') . '.jpg';
        $gd = imagecreatetruecolor(250, 180);
        $color = imagecolorallocate($gd, 120, 200, 80);
        imagefill($gd, 0, 0, $color);
        imagejpeg($gd, $tempPath, 90);
        imagedestroy($gd);

        $file = new UploadedFile($tempPath, 'dim_test.jpg', 'image/jpeg', null, true);

        $response = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'high',
        ]);

        @unlink($tempPath);

        $response->assertStatus(200);
        $filename = $response->json('filename');
        $processedPath = Storage::disk('file_converter')->path('processed/' . $filename);
        $this->assertFileExists($processedPath);

        $size = getimagesize($processedPath);
        $this->assertEquals(250, $size[0], 'Width must match original 250px without downscaling');
        $this->assertEquals(180, $size[1], 'Height must match original 180px without downscaling');
    }

    public function test_png_and_gif_compression_strictly_reduces_size_without_size_inflation(): void
    {
        // 1. Test PNG compression
        $pngTemp = tempnam(sys_get_temp_dir(), 'test_png') . '.png';
        $gd = imagecreatetruecolor(200, 200);
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 200; $y++) {
                imagesetpixel($gd, $x, $y, imagecolorallocate($gd, $x % 256, $y % 256, ($x + $y) % 256));
            }
        }
        imagepng($gd, $pngTemp, 0); // Raw uncompressed PNG
        imagedestroy($gd);

        $pngOriginalSize = filesize($pngTemp);
        $pngFile = new UploadedFile($pngTemp, 'canvas.png', 'image/png', null, true);

        $pngResp = $this->postJson('/api/files/compress', [
            'file' => $pngFile,
            'compression_level' => 'high',
        ]);
        @unlink($pngTemp);

        $pngResp->assertStatus(200);
        $this->assertLessThan($pngOriginalSize, $pngResp->json('processed_size'), 'PNG processed size must be strictly smaller than original');

        // 2. Test GIF compression
        $gifTemp = tempnam(sys_get_temp_dir(), 'test_gif') . '.gif';
        $gdGif = imagecreatetruecolor(200, 200);
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 200; $y++) {
                imagesetpixel($gdGif, $x, $y, imagecolorallocate($gdGif, ($x * 2) % 256, ($y * 2) % 256, 120));
            }
        }
        imagegif($gdGif, $gifTemp);
        imagedestroy($gdGif);

        $gifOriginalSize = filesize($gifTemp);
        $gifFile = new UploadedFile($gifTemp, 'anim.gif', 'image/gif', null, true);

        $gifResp = $this->postJson('/api/files/compress', [
            'file' => $gifFile,
            'compression_level' => 'high',
        ]);
        @unlink($gifTemp);

        $gifResp->assertStatus(200);
        $this->assertLessThanOrEqual($gifOriginalSize, $gifResp->json('processed_size'), 'GIF processed size must not exceed original');
    }

    /**
     * Validation Tests:
     * - missing file
     * - unsupported file (TXT, EXE)
     * - oversized file
     * - missing format
     * - invalid format
     * - invalid compression level
     */
    public function test_convert_fails_when_file_is_missing(): void
    {
        $response = $this->postJson('/api/files/convert', [
            'format' => 'webp',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonValidationErrors(['file']);
    }

    public function test_convert_fails_when_txt_file_provided(): void
    {
        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'png',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonValidationErrors(['file']);
    }

    public function test_convert_fails_when_exe_file_provided(): void
    {
        $file = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'png',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonValidationErrors(['file']);
    }

    public function test_convert_fails_when_oversized_file_provided(): void
    {
        // 102401 KB exceeds the 102400 KB limit
        $file = UploadedFile::fake()->create('huge.jpg', 102401, 'image/jpeg');

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'webp',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_convert_fails_when_format_is_missing(): void
    {
        $file = UploadedFile::fake()->image('sample.png', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['format']);
    }

    public function test_convert_fails_when_invalid_format_provided(): void
    {
        $file = UploadedFile::fake()->image('sample.png', 50, 50);

        $response = $this->postJson('/api/files/convert', [
            'file' => $file,
            'format' => 'docx', // Unsupported format
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['format']);
    }

    public function test_compress_fails_when_compression_level_is_invalid(): void
    {
        $file = UploadedFile::fake()->image('sample.png', 50, 50);

        $response = $this->postJson('/api/files/compress', [
            'file' => $file,
            'compression_level' => 'ultra-maximum',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['compression_level']);
    }

    /**
     * Download & Security Tests:
     * - valid generated file
     * - missing generated file
     * - invalid filename format
     * - path traversal attempt
     */
    public function test_can_download_valid_generated_file(): void
    {
        $filename = 'converted_1234567890abcdef.webp';
        Storage::disk('file_converter')->put('processed/' . $filename, 'fake-binary-content');

        $response = $this->get('/api/files/download/' . $filename);

        $response->assertStatus(200);
        $this->assertEquals('fake-binary-content', $response->streamedContent());
    }

    public function test_download_returns_404_for_missing_file(): void
    {
        $response = $this->getJson('/api/files/download/converted_0000000000000000.webp');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'File not found or link has expired.',
            ]);
    }

    public function test_download_returns_404_for_invalid_filename_format(): void
    {
        $response = $this->getJson('/api/files/download/some_random_file.txt');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'File not found or link has expired.',
            ]);
    }

    public function test_download_blocks_path_traversal_attempts(): void
    {
        $attempts = [
            '../.env',
            '..%2F.env',
            '../../storage/app/secret.txt',
            'converted_1234567890abcdef.webp/../../../.env',
        ];

        foreach ($attempts as $attempt) {
            $response = $this->get('/api/files/download/' . $attempt);
            $this->assertTrue(in_array($response->status(), [404, 400]));
        }
    }

    /**
     * Supported Formats Endpoint Test
     */
    public function test_supported_formats_endpoint_returns_allowed_formats(): void
    {
        $response = $this->getJson('/api/files/supported-formats');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'formats' => ['jpg', 'jpeg', 'png', 'webp'],
            ]);
    }

    /**
     * Cleanup Command Test
     */
    public function test_cleanup_artisan_command_removes_expired_files(): void
    {
        $expiredFile = 'processed/converted_expired000000.webp';
        $freshFile = 'processed/converted_fresh00000000.webp';

        Storage::disk('file_converter')->put($expiredFile, 'expired-data');
        Storage::disk('file_converter')->put($freshFile, 'fresh-data');

        // Test running cleanup command with 0 minutes lifetime (everything is expired)
        $this->artisan('file-converter:cleanup', ['--minutes' => 0])
            ->assertExitCode(0);

        Storage::disk('file_converter')->assertMissing($expiredFile);
        Storage::disk('file_converter')->assertMissing($freshFile);
    }
}
