<?php

namespace App\Jobs;

use App\Models\Recording;
use App\Services\RecordingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job to monitor active recordings and handle stuck/stale recordings.
 * Runs periodically via scheduler.
 */
class MonitorRecordings implements ShouldQueue
{
    use Queueable;

    public $timeout = 300; // 5 minutes

    public $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(RecordingService $recordingService): void
    {
        Log::debug('MonitorRecordings: Checking active recordings');

        // Find recordings that are stuck in "recording" status
        $this->checkStuckRecordings();

        // Find recordings that should have started but didn't
        $this->checkMissedRecordings();

        // Check disk space for all active recordings
        $this->checkDiskSpace($recordingService);
    }

    /**
     * Check for recordings stuck in recording status
     */
    protected function checkStuckRecordings(): void
    {
        // Find recordings that have been "recording" for longer than expected
        $recordings = Recording::recording()
            ->where('actual_start', '<', now()->subHours(12)) // Max 12 hour recording
            ->get();

        foreach ($recordings as $recording) {
            // Check if end time has passed
            if (now()->greaterThan($recording->getActualEndTime()->addMinutes(10))) {
                Log::warning("Recording {$recording->id} is stuck, marking as failed");

                $recording->markAsFailed('Recording exceeded maximum duration');

                // Try to process what we have
                if ($recording->segments()->where('status', 'completed')->exists()) {
                    ProcessRecording::dispatch($recording);
                }
            }
        }
    }

    /**
     * Check for recordings that should have started but didn't
     */
    protected function checkMissedRecordings(): void
    {
        // Find recordings scheduled in the past that are still "scheduled"
        $missedRecordings = Recording::scheduled()
            ->where('scheduled_start', '<', now()->subMinutes(5))
            ->get();

        foreach ($missedRecordings as $recording) {
            Log::warning("Recording {$recording->id} missed its start time");

            // If it's not too late, try to start it anyway
            $endTime = $recording->getActualEndTime();
            if (now()->lessThan($endTime)) {
                Log::info("Attempting to start missed recording {$recording->id}");
                StartRecording::dispatch($recording);
            } else {
                $recording->markAsFailed('Missed scheduled start time');
            }
        }
    }

    /**
     * Check disk space for active recordings
     */
    protected function checkDiskSpace(RecordingService $recordingService): void
    {
        $recordingsDir = $recordingService->getRecordingsDirectory();
        $freeSpace = disk_free_space($recordingsDir);

        // Warn if less than 5GB free
        if ($freeSpace < 5 * 1024 * 1024 * 1024) {
            Log::warning('Low disk space for recordings', [
                'free_bytes' => $freeSpace,
                'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
            ]);

            // Cancel scheduled recordings if critically low (<1GB)
            if ($freeSpace < 1024 * 1024 * 1024) {
                Log::error('Critical disk space - cancelling scheduled recordings');

                Recording::scheduled()
                    ->update([
                        'status' => 'cancelled',
                        'last_error' => 'Insufficient disk space',
                    ]);
            }
        }
    }
}
