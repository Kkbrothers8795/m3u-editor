<?php

namespace App\Jobs;

use App\Models\Recording;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job to check for recordings that should start soon and dispatch them.
 * This runs every minute via scheduler.
 */
class ScheduleRecordings implements ShouldQueue
{
    use Queueable;

    public $timeout = 60;

    public $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $now = now();

        // Find recordings scheduled to start in the next 2 minutes
        // We check 2 minutes ahead to account for scheduler timing
        $upcomingRecordings = Recording::scheduled()
            ->whereBetween('scheduled_start', [
                $now->copy()->subMinute(), // Include slightly past recordings
                $now->copy()->addMinutes(2),
            ])
            ->get();

        Log::info("ScheduleRecordings: Found {$upcomingRecordings->count()} recordings to schedule");

        foreach ($upcomingRecordings as $recording) {
            // Calculate when to actually start (including pre-padding)
            $actualStart = $recording->getActualStartTime();

            // If start time is in the past or within 10 seconds, start immediately
            if ($actualStart->isPast() || $actualStart->diffInSeconds($now, false) < 10) {
                Log::info("Starting recording {$recording->id} immediately");
                StartRecording::dispatch($recording);
            } else {
                // Schedule for future start
                $delay = $actualStart->diffInSeconds($now);
                Log::info("Scheduling recording {$recording->id} to start in {$delay} seconds");
                StartRecording::dispatch($recording)->delay($actualStart);
            }
        }

        // Check for failed recordings that can be retried
        $this->retryFailedRecordings();
    }

    /**
     * Retry failed recordings that are eligible
     */
    protected function retryFailedRecordings(): void
    {
        $retryableRecordings = Recording::retryable()
            ->where(function ($query) {
                // Retry if last attempt was more than 5 minutes ago
                $query->whereNull('last_retry_at')
                    ->orWhere('last_retry_at', '<', now()->subMinutes(5));
            })
            ->get();

        foreach ($retryableRecordings as $recording) {
            Log::info("Retrying failed recording {$recording->id} (attempt {$recording->retry_count}/{$recording->max_retries})");

            $recording->incrementRetry();
            $recording->update(['status' => 'scheduled']);

            // Schedule retry
            StartRecording::dispatch($recording);
        }
    }
}
