<?php

namespace App\Console\Commands;

use App\Services\FileProcessingService;
use Illuminate\Console\Command;

class CleanupProcessedFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'file-converter:cleanup {--minutes= : Retention lifetime in minutes (default from config)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up temporary uploads and processed files older than the configured lifetime';

    /**
     * Execute the console command.
     */
    public function handle(FileProcessingService $fileProcessingService): int
    {
        $minutes = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) config('file_converter.file_lifetime', 60);

        $this->info("Scanning for temporary and processed files older than {$minutes} minutes...");

        $deletedCount = $fileProcessingService->cleanExpiredFiles($minutes);

        $this->info("Cleanup completed. Deleted {$deletedCount} expired file(s).");

        return Command::SUCCESS;
    }
}
