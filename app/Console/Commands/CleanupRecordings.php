<?php

namespace App\Console\Commands;

use App\Services\RecordingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupRecordings extends Command
{
    protected $signature = 'recordings:cleanup {--days=30 : Number of days to keep recordings} {--force : Force cleanup without confirmation}';

    protected $description = 'Clean up old completed recordings';

    public function handle(RecordingService $recordingService): int
    {
        $days = (int) $this->option('days');
        $force = $this->option('force');

        $this->info("Cleaning up recordings older than {$days} days...");

        if (! $force) {
            if (! $this->confirm('This will permanently delete old recording files. Continue?')) {
                $this->info('Cleanup cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $deleted = $recordingService->cleanupOldRecordings($days);

            $this->info("Successfully deleted {$deleted} old recordings.");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to cleanup recordings: {$e->getMessage()}");
            Log::error('Recording cleanup failed', ['exception' => $e]);

            return self::FAILURE;
        }
    }
}
