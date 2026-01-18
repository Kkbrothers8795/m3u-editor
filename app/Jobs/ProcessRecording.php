<?php

namespace App\Jobs;

use App\Models\Recording;
use App\Services\RecordingService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job to process a completed recording.
 * Merges segments, creates STRM file, and notifies user.
 */
class ProcessRecording implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600; // 1 hour

    public $tries = 3;

    public function __construct(public Recording $recording) {}

    /**
     * Execute the job.
     */
    public function handle(RecordingService $recordingService): void
    {
        Log::info("Processing completed recording {$this->recording->id}");

        try {
            // Merge segments if needed
            if ($this->recording->segments()->count() > 0) {
                if (! $recordingService->mergeSegments($this->recording)) {
                    throw new \RuntimeException('Failed to merge recording segments');
                }
            }

            // Create STRM file if configured
            // TODO: Make this configurable per recording
            // $this->createStrmFile($recordingService);

            // Notify user
            $this->notifyUser();

            Log::info("Recording {$this->recording->id} processed successfully");
        } catch (\Exception $e) {
            Log::error("Failed to process recording {$this->recording->id}: ".$e->getMessage());

            // Don't mark as failed if already completed
            // Just log the processing error
        }
    }

    /**
     * Create STRM file for the recording
     */
    protected function createStrmFile(RecordingService $recordingService): void
    {
        // Default sync location from settings or use recordings directory
        $syncLocation = $recordingService->getRecordingsDirectory().'/library';

        $strmMapping = $recordingService->createStrmFile($this->recording, $syncLocation);

        if ($strmMapping) {
            Log::info("Created STRM file for recording {$this->recording->id}: {$strmMapping->current_path}");
        }
    }

    /**
     * Notify user that recording is complete
     */
    protected function notifyUser(): void
    {
        try {
            $user = $this->recording->user;

            if (! $user) {
                return;
            }

            Notification::make()
                ->title('Recording Complete')
                ->body("Recording '{$this->recording->title}' has completed successfully.")
                ->success()
                ->sendToDatabase($user);
        } catch (\Exception $e) {
            Log::warning("Failed to send notification for recording {$this->recording->id}: ".$e->getMessage());
        }
    }
}
