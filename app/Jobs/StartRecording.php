<?php

namespace App\Jobs;

use App\Models\Recording;
use App\Services\M3uProxyService;
use App\Services\RecordingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Job to start a scheduled recording.
 * This job initiates the recording process and monitors it until completion.
 */
class StartRecording implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600 * 12; // 12 hours max

    public $tries = 1;

    public function __construct(public Recording $recording) {}

    /**
     * Execute the job.
     */
    public function handle(RecordingService $recordingService): void
    {
        // Refresh the recording
        $this->recording->refresh();

        // Check if recording was cancelled
        if ($this->recording->status === 'cancelled') {
            Log::info("Recording {$this->recording->id} was cancelled, skipping");

            return;
        }

        // Check if already recording
        if ($this->recording->status === 'recording') {
            Log::warning("Recording {$this->recording->id} is already in progress");

            return;
        }

        Log::info("Starting recording {$this->recording->id}: {$this->recording->title}");

        // Verify connection availability
        if (! $this->recording->canRecord()) {
            $this->recording->markAsFailed('No available connections to start recording');

            return;
        }

        // Verify disk space
        $requiredSpace = $recordingService->estimateRequiredSpace($this->recording);
        if (! $recordingService->hasEnoughDiskSpace($requiredSpace)) {
            $this->recording->markAsFailed('Insufficient disk space');

            return;
        }

        // Mark as recording
        $this->recording->update([
            'status' => 'recording',
            'actual_start' => now(),
        ]);

        try {
            // Create the stream via proxy
            $streamData = $this->createProxyStream();

            if (! $streamData) {
                throw new \RuntimeException('Failed to create proxy stream');
            }

            // Start recording process
            $this->recordStream($recordingService, $streamData);

            // Recording completed successfully
            $this->recording->update([
                'status' => 'completed',
                'actual_end' => now(),
            ]);

            Log::info("Recording {$this->recording->id} completed successfully");

            // Dispatch post-processing job
            ProcessRecording::dispatch($this->recording);
        } catch (\Exception $e) {
            Log::error("Recording {$this->recording->id} failed: ".$e->getMessage(), [
                'exception' => $e,
            ]);

            $this->recording->markAsFailed($e->getMessage());
        }
    }

    /**
     * Create a transcoded stream via the proxy
     */
    protected function createProxyStream(): ?array
    {
        $proxyService = app(M3uProxyService::class);
        $streamUrl = $this->recording->getStreamUrl();

        if (! $streamUrl) {
            Log::error("No stream URL found for recording {$this->recording->id}");

            return null;
        }

        $profile = $this->recording->streamProfile;

        if (! $profile) {
            Log::error("No stream profile found for recording {$this->recording->id}");

            return null;
        }

        try {
            // Get profile template variables
            $profileVars = $profile->getTemplateVariables([
                'format' => $profile->format,
            ]);

            // Create stream via proxy API using the new public method
            $response = $proxyService->createTranscodedStreamForRecording(
                url: $streamUrl,
                profile: $profile->getProfileIdentifier(),
                profileVariables: $profileVars,
                metadata: [
                    'recording_id' => $this->recording->id,
                    'type' => 'dvr_recording',
                    'title' => $this->recording->title,
                ]
            );

            if (! $response || ! isset($response['stream_id'])) {
                Log::error('Proxy stream creation failed', ['response' => $response]);

                return null;
            }

            // Update recording metadata with stream info
            $this->recording->update([
                'recording_metadata' => array_merge(
                    $this->recording->recording_metadata ?? [],
                    [
                        'stream_id' => $response['stream_id'],
                        'proxy_url' => $response['stream_endpoint'] ?? null,
                    ]
                ),
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error("Failed to create proxy stream for recording {$this->recording->id}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Record the stream to file
     */
    protected function recordStream(RecordingService $recordingService, array $streamData): void
    {
        $durationSeconds = $this->recording->getTotalDurationSeconds();

        // Create initial segment
        $segment = $recordingService->createSegment($this->recording, 1);

        // Build stream URL (use proxy endpoint)
        $proxyUrl = config('proxy.m3u_proxy_host');
        $streamEndpoint = $streamData['stream_endpoint'] ?? $streamData['direct_url'] ?? null;

        if (! $streamEndpoint) {
            throw new \RuntimeException('No stream endpoint found in proxy response');
        }

        $fullStreamUrl = $proxyUrl.$streamEndpoint;

        Log::info("Recording from URL: {$fullStreamUrl}");

        // Download stream using FFmpeg
        $process = $recordingService->downloadStream(
            url: $fullStreamUrl,
            outputPath: $segment->file_path,
            durationSeconds: $durationSeconds
        );

        // Start the process
        $process->start();

        // Monitor the process
        $lastCheck = time();
        while ($process->isRunning()) {
            // Check every 10 seconds
            sleep(10);

            // Update segment file size
            if (time() - $lastCheck >= 30) {
                $segment->updateFileSize();
                $lastCheck = time();
            }

            // Check if we should stop (end time reached)
            if (now()->greaterThan($this->recording->getActualEndTime())) {
                Log::info("Recording {$this->recording->id} reached end time, stopping");
                $process->stop();
                break;
            }

            // Check if recording was cancelled
            $this->recording->refresh();
            if ($this->recording->status === 'cancelled') {
                Log::info("Recording {$this->recording->id} was cancelled, stopping");
                $process->stop();

                return;
            }
        }

        // Wait for process to finish
        $process->wait();

        // Check if successful
        if ($process->isSuccessful() || $process->getExitCode() === 255) {
            // Exit code 255 can occur when stream ends naturally
            $segment->markCompleted();
            Log::info("Recording segment completed: {$segment->file_path}");
        } else {
            $error = $process->getErrorOutput();
            $segment->markFailed($error);
            throw new \RuntimeException("FFmpeg process failed: {$error}");
        }

        // Calculate actual duration
        if ($this->recording->actual_start && $this->recording->actual_end) {
            $duration = $this->recording->actual_end->diffInSeconds($this->recording->actual_start);
            $this->recording->update(['duration_seconds' => $duration]);
        }
    }
}
