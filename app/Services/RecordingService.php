<?php

namespace App\Services;

use App\Models\Recording;
use App\Models\RecordingSegment;
use App\Models\StrmFileMapping;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RecordingService
{
    public function __construct(protected GeneralSettings $settings) {}

    /**
     * Get the base recordings directory (for final output)
     */
    public function getRecordingsDirectory(): string
    {
        $baseDir = $this->settings->recording_file_location
            ?? config('filesystems.disks.recordings.root', storage_path('app/recordings'));

        if (! is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        return $baseDir;
    }

    /**
     * Get the temporary recordings directory (for segments during recording)
     */
    public function getTempRecordingsDirectory(): string
    {
        $tempDir = config('filesystems.disks.recordings_tmp.root', storage_path('app/recordings/tmp'));

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        return $tempDir;
    }

    /**
     * Get the output directory for a recording (final location)
     */
    public function getRecordingDirectory(Recording $recording): string
    {
        $baseDir = $this->getRecordingsDirectory();
        $pathStructure = $this->settings->recording_file_path_structure ?? ['type'];

        $path = $baseDir;

        foreach ($pathStructure as $component) {
            $segment = match ($component) {
                'type' => $this->getTypeFolder($recording),
                'playlist' => $this->getPlaylistFolder($recording),
                'category' => $this->getCategoryFolder($recording),
                'series' => $this->getSeriesFolder($recording),
                'season' => $this->getSeasonFolder($recording),
                default => null,
            };

            if ($segment) {
                $path .= '/'.$this->sanitizeFilename($segment);
            }
        }

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * Get the temporary output directory for recording segments
     */
    public function getTempRecordingDirectory(Recording $recording): string
    {
        $tempDir = $this->getTempRecordingsDirectory();
        $recordingDir = $tempDir.'/recording_'.$recording->id;

        if (! is_dir($recordingDir)) {
            mkdir($recordingDir, 0755, true);
        }

        return $recordingDir;
    }

    /**
     * Get the output file path for a recording (final location)
     */
    public function getOutputPath(Recording $recording): string
    {
        $dir = $this->getRecordingDirectory($recording);
        $filename = $this->buildFilename($recording);
        $extension = $this->getFileExtension($recording);

        return $dir.'/'.$filename.'.'.$extension;
    }

    /**
     * Build filename with metadata
     */
    protected function buildFilename(Recording $recording): string
    {
        $metadata = $this->settings->recording_filename_metadata ?? [];
        $parts = [];

        // Base title
        $parts[] = $recording->title;

        // Add metadata based on settings
        if (in_array('date', $metadata) && $recording->scheduled_start) {
            $parts[] = $recording->scheduled_start->format('Y-m-d');
        }

        if (in_array('time', $metadata) && $recording->scheduled_start) {
            $parts[] = $recording->scheduled_start->format('H-i');
        }

        if (in_array('year', $metadata)) {
            $year = $this->getRecordableYear($recording);
            if ($year) {
                $parts[] = "({$year})";
            }
        }

        if ($recording->recordable_type === 'App\\Models\\Episode') {
            if (in_array('season', $metadata) || in_array('episode', $metadata)) {
                $episode = $recording->recordable;
                if ($episode) {
                    $seasonEp = '';
                    if (in_array('season', $metadata) && $episode->season) {
                        $seasonEp .= 'S'.str_pad($episode->season, 2, '0', STR_PAD_LEFT);
                    }
                    if (in_array('episode', $metadata) && $episode->episode_num) {
                        $seasonEp .= 'E'.str_pad($episode->episode_num, 2, '0', STR_PAD_LEFT);
                    }
                    if ($seasonEp) {
                        $parts[] = $seasonEp;
                    }
                }
            }
        }

        $filename = implode(' ', $parts);

        return $this->sanitizeFilename($filename);
    }

    /**
     * Get type folder name
     */
    protected function getTypeFolder(Recording $recording): string
    {
        return match ($recording->recordable_type) {
            'App\\Models\\Channel' => 'Channels',
            'App\\Models\\Episode' => 'Episodes',
            'App\\Models\\Series' => 'Series',
            default => 'Other',
        };
    }

    /**
     * Get playlist folder name
     */
    protected function getPlaylistFolder(Recording $recording): ?string
    {
        $recordable = $recording->recordable;
        if (! $recordable) {
            return null;
        }

        $playlistName = match ($recording->recordable_type) {
            'App\\Models\\Channel' => $recordable->playlist?->name,
            'App\\Models\\Episode' => $recordable->series?->playlist?->name,
            'App\\Models\\Series' => $recordable->playlist?->name,
            default => null,
        };

        return $playlistName ? $this->applyNameFiltering($playlistName) : null;
    }

    /**
     * Get category/group folder name
     */
    protected function getCategoryFolder(Recording $recording): ?string
    {
        $recordable = $recording->recordable;
        if (! $recordable) {
            return null;
        }

        $categoryName = match ($recording->recordable_type) {
            'App\\Models\\Channel' => $recordable->group?->name,
            'App\\Models\\Episode' => $recordable->series?->category?->name,
            'App\\Models\\Series' => $recordable->category?->name,
            default => null,
        };

        return $categoryName ? $this->applyNameFiltering($categoryName) : null;
    }

    /**
     * Get series folder name
     */
    protected function getSeriesFolder(Recording $recording): ?string
    {
        if ($recording->recordable_type !== 'App\\Models\\Episode') {
            return null;
        }

        $episode = $recording->recordable;
        $seriesName = $episode?->series?->name;

        return $seriesName ? $this->applyNameFiltering($seriesName) : null;
    }

    /**
     * Get season folder name
     */
    protected function getSeasonFolder(Recording $recording): ?string
    {
        if ($recording->recordable_type !== 'App\\Models\\Episode') {
            return null;
        }

        $episode = $recording->recordable;
        if (! $episode || ! $episode->season) {
            return null;
        }

        return 'Season '.str_pad($episode->season, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Get year from recordable
     */
    protected function getRecordableYear(Recording $recording): ?string
    {
        $recordable = $recording->recordable;
        if (! $recordable) {
            return null;
        }

        return match ($recording->recordable_type) {
            'App\\Models\\Series' => $recordable->release_date ? substr($recordable->release_date, 0, 4) : null,
            'App\\Models\\Episode' => $recordable->series?->release_date ? substr($recordable->series->release_date, 0, 4) : null,
            default => null,
        };
    }

    /**
     * Apply name filtering based on settings
     */
    protected function applyNameFiltering(string $name): string
    {
        if (! $this->settings->recording_name_filter_enabled) {
            return $name;
        }

        $patterns = $this->settings->recording_name_filter_patterns ?? [];
        foreach ($patterns as $pattern) {
            $name = str_replace($pattern, '', $name);
        }

        return trim($name);
    }

    /**
     * Get file extension based on stream profile format
     */
    protected function getFileExtension(Recording $recording): string
    {
        if (! $recording->streamProfile) {
            return 'ts'; // Default
        }

        return match ($recording->streamProfile->format) {
            'm3u8' => 'ts', // HLS will be converted to TS
            'mp4' => 'mp4',
            'mkv' => 'mkv',
            'mov' => 'mov',
            default => 'ts',
        };
    }

    /**
     * Sanitize filename using settings
     */
    protected function sanitizeFilename(string $name): string
    {
        // Apply name filtering first
        $name = $this->applyNameFiltering($name);

        // Use PlaylistService logic if clean special chars is enabled
        if ($this->settings->recording_clean_special_chars) {
            $replaceChar = $this->settings->recording_replace_char ?? 'space';
            $name = PlaylistService::makeFilesystemSafe($name, $replaceChar);

            // Remove consecutive replacement characters if enabled
            if ($this->settings->recording_remove_consecutive_chars && $replaceChar !== 'remove') {
                $char = match ($replaceChar) {
                    'space' => ' ',
                    'dash' => '-',
                    'underscore' => '_',
                    'period' => '.',
                    default => ' ',
                };

                // Remove consecutive occurrences
                $pattern = preg_quote($char, '/');
                $name = preg_replace("/{$pattern}+/", $char, $name);
            }
        }

        return trim($name) ?: 'Unnamed';
    }

    /**
     * Create a new segment for recording (in temporary directory)
     */
    public function createSegment(Recording $recording, int $segmentNumber): RecordingSegment
    {
        $dir = $this->getTempRecordingDirectory($recording);
        $extension = $this->getFileExtension($recording);
        $segmentPath = $dir.'/segment_'.str_pad($segmentNumber, 3, '0', STR_PAD_LEFT).'.'.$extension;

        return $recording->segments()->create([
            'segment_number' => $segmentNumber,
            'file_path' => $segmentPath,
            'started_at' => now(),
            'status' => 'recording',
        ]);
    }

    /**
     * Merge all completed segments into final output file
     */
    public function mergeSegments(Recording $recording): bool
    {
        $segments = $recording->segments()
            ->where('status', 'completed')
            ->orderBy('segment_number')
            ->get();

        if ($segments->isEmpty()) {
            Log::error("No completed segments found for recording {$recording->id}");

            return false;
        }

        // If only one segment, just rename it
        if ($segments->count() === 1) {
            $segment = $segments->first();
            $outputPath = $this->getOutputPath($recording);

            if (rename($segment->file_path, $outputPath)) {
                $recording->update([
                    'output_path' => $outputPath,
                    'file_size_bytes' => $segment->file_size_bytes,
                ]);

                return true;
            }

            return false;
        }

        // Multiple segments - use FFmpeg to concatenate
        return $this->concatenateWithFFmpeg($recording, $segments);
    }

    /**
     * Concatenate segments using FFmpeg
     */
    protected function concatenateWithFFmpeg(Recording $recording, $segments): bool
    {
        $outputPath = $this->getOutputPath($recording);
        $concatFile = $this->getRecordingDirectory($recording).'/concat_list.txt';

        // Create concat file list
        $fileList = $segments->map(function ($segment) {
            return "file '".str_replace("'", "'\\''", $segment->file_path)."'";
        })->implode("\n");

        file_put_contents($concatFile, $fileList);

        // Run FFmpeg concat
        $command = [
            'ffmpeg',
            '-f', 'concat',
            '-safe', '0',
            '-i', $concatFile,
            '-c', 'copy',
            $outputPath,
        ];

        $process = new Process($command);
        $process->setTimeout(3600); // 1 hour timeout
        $process->run();

        // Cleanup concat file
        @unlink($concatFile);

        if (! $process->isSuccessful()) {
            Log::error("FFmpeg concat failed for recording {$recording->id}: ".$process->getErrorOutput());

            return false;
        }

        // Update recording with output path
        $fileSize = file_exists($outputPath) ? filesize($outputPath) : null;
        $recording->update([
            'output_path' => $outputPath,
            'file_size_bytes' => $fileSize,
        ]);

        // Delete segment files
        foreach ($segments as $segment) {
            @unlink($segment->file_path);
        }

        Log::info("Successfully merged {$segments->count()} segments for recording {$recording->id}");

        return true;
    }

    /**
     * Download stream to file using curl or ffmpeg
     */
    public function downloadStream(string $url, string $outputPath, ?int $durationSeconds = null): Process
    {
        $command = [
            'ffmpeg',
            '-i', $url,
            '-c', 'copy',
        ];

        // Add duration limit if specified
        if ($durationSeconds) {
            $command[] = '-t';
            $command[] = (string) $durationSeconds;
        }

        $command[] = $outputPath;

        $process = new Process($command);
        $process->setTimeout(null); // No timeout for long recordings

        return $process;
    }

    /**
     * Create .strm file pointing to recorded file
     */
    public function createStrmFile(Recording $recording, string $syncLocation): ?StrmFileMapping
    {
        if (! $recording->output_path || ! file_exists($recording->output_path)) {
            Log::warning("Cannot create STRM file for recording {$recording->id}: output file not found");

            return null;
        }

        $recordable = $recording->recordable;

        // Generate file URL (could be local file:// or http:// depending on setup)
        $fileUrl = 'file://'.realpath($recording->output_path);

        // Determine path based on recordable type
        $strmPath = $this->getStrmPath($recording, $syncLocation);

        return StrmFileMapping::syncFile(
            syncable: $recordable,
            syncLocation: $syncLocation,
            expectedPath: $strmPath,
            url: $fileUrl,
            pathOptions: ['recording_id' => $recording->id]
        );
    }

    /**
     * Get the .strm file path for a recording
     */
    protected function getStrmPath(Recording $recording, string $syncLocation): string
    {
        $sanitizedTitle = $this->sanitizeFilename($recording->title);
        $recordable = $recording->recordable;

        if ($recordable instanceof \App\Models\Channel) {
            $groupName = $this->sanitizeFilename($recordable->group?->title ?? 'Recordings');

            return $syncLocation.'/'.$groupName.'/'.$sanitizedTitle.'.strm';
        }

        if ($recordable instanceof \App\Models\Episode) {
            $series = $recordable->series;
            $seriesName = $this->sanitizeFilename($series->title);
            $seasonNum = str_pad($recordable->season_num, 2, '0', STR_PAD_LEFT);
            $episodeNum = str_pad($recordable->episode_num, 2, '0', STR_PAD_LEFT);

            return $syncLocation.'/'.$seriesName.'/Season '.$seasonNum.'/'.$seriesName.' - S'.$seasonNum.'E'.$episodeNum.'.strm';
        }

        return $syncLocation.'/Recordings/'.$sanitizedTitle.'.strm';
    }

    /**
     * Check available disk space
     */
    public function hasEnoughDiskSpace(int $requiredBytes): bool
    {
        $recordingsDir = $this->getRecordingsDirectory();
        $freeSpace = disk_free_space($recordingsDir);

        // Require 10% buffer
        return $freeSpace > ($requiredBytes * 1.1);
    }

    /**
     * Estimate required disk space for recording
     */
    public function estimateRequiredSpace(Recording $recording): int
    {
        $durationSeconds = $recording->getTotalDurationSeconds();

        // Estimate based on profile format
        // Default to 2 Mbps (250 KB/s) for TS
        $bytesPerSecond = 250000;

        if ($recording->streamProfile) {
            // Try to parse bitrate from profile args
            $args = $recording->streamProfile->args;
            if (preg_match('/-b:v\s+(\d+)k/i', $args, $matches)) {
                $videoBitrate = (int) $matches[1] * 1000 / 8; // Convert to bytes
                $bytesPerSecond = $videoBitrate;
            }
        }

        return $durationSeconds * $bytesPerSecond;
    }

    /**
     * Clean up old recordings based on retention policy
     */
    public function cleanupOldRecordings(int $daysToKeep = 30): int
    {
        $cutoff = now()->subDays($daysToKeep);
        $deleted = 0;

        $oldRecordings = Recording::where('status', 'completed')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($oldRecordings as $recording) {
            if ($this->deleteRecording($recording)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete a recording and its files
     */
    public function deleteRecording(Recording $recording): bool
    {
        try {
            // Delete output file
            if ($recording->output_path && file_exists($recording->output_path)) {
                @unlink($recording->output_path);
            }

            // Delete segment files
            foreach ($recording->segments as $segment) {
                if ($segment->fileExists()) {
                    @unlink($segment->file_path);
                }
            }

            // Delete recording directory if empty
            $dir = $this->getRecordingDirectory($recording);
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                @rmdir($dir);
            }

            // Soft delete the recording
            $recording->delete();

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to delete recording {$recording->id}: ".$e->getMessage());

            return false;
        }
    }
}
