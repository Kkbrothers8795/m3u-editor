<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Recording extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'recordable_type',
        'recordable_id',
        'user_id',
        'stream_profile_id',
        'title',
        'type',
        'status',
        'scheduled_start',
        'scheduled_end',
        'pre_padding_seconds',
        'post_padding_seconds',
        'actual_start',
        'actual_end',
        'output_path',
        'file_size_bytes',
        'duration_seconds',
        'retry_count',
        'max_retries',
        'last_error',
        'last_retry_at',
        'recording_metadata',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'last_retry_at' => 'datetime',
        'recording_metadata' => 'array',
        'file_size_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'pre_padding_seconds' => 'integer',
        'post_padding_seconds' => 'integer',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
    ];

    /**
     * Get the recordable entity (Channel, Episode, Series, etc.)
     */
    public function recordable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created this recording
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the stream profile for this recording
     */
    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    /**
     * Get the recording segments
     */
    public function segments(): HasMany
    {
        return $this->hasMany(RecordingSegment::class);
    }

    /**
     * Check if this recording can start based on connection availability
     */
    public function canRecord(): bool
    {
        // Must have a stream profile (proxy required for DVR)
        if (! $this->streamProfile) {
            Log::warning("Recording {$this->id} has no stream profile");

            return false;
        }

        // Check recordable-specific availability
        if ($this->recordable instanceof Channel) {
            return $this->checkChannelAvailability();
        }

        if ($this->recordable instanceof Episode) {
            return $this->checkEpisodeAvailability();
        }

        // Default to true for other types
        return true;
    }

    /**
     * Check if a channel has available connections
     */
    protected function checkChannelAvailability(): bool
    {
        $channel = $this->recordable;
        $playlist = $channel->playlist;

        if (! $playlist) {
            return false;
        }

        // If using profiles, check profile availability
        if ($playlist->profiles_enabled) {
            return $this->hasAvailableProfile($playlist);
        }

        // Otherwise check basic Xtream connection count
        if ($playlist->xtream) {
            return $this->checkXtreamAvailability($playlist);
        }

        // For non-Xtream playlists, assume available
        return true;
    }

    /**
     * Check if an episode's series has available connections
     */
    protected function checkEpisodeAvailability(): bool
    {
        $episode = $this->recordable;
        $series = $episode->series;
        $playlist = $series?->playlist;

        if (! $playlist) {
            return false;
        }

        // Similar logic to channel
        if ($playlist->profiles_enabled) {
            return $this->hasAvailableProfile($playlist);
        }

        if ($playlist->xtream) {
            return $this->checkXtreamAvailability($playlist);
        }

        return true;
    }

    /**
     * Check if playlist has available profile connections
     */
    protected function hasAvailableProfile(Playlist $playlist): bool
    {
        try {
            $profiles = $playlist->getActiveProfilesWithCapacity();

            return count($profiles) > 0;
        } catch (\Exception $e) {
            Log::error("Failed to check profile availability for recording {$this->id}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Check Xtream API connection availability
     */
    protected function checkXtreamAvailability(Playlist $playlist): bool
    {
        try {
            $xtreamStatus = $playlist->xtream_status;
            $maxConnections = (int) ($xtreamStatus['user_info']['max_connections'] ?? 1);
            $activeConnections = (int) ($xtreamStatus['user_info']['active_cons'] ?? 0);

            return $activeConnections < $maxConnections;
        } catch (\Exception $e) {
            Log::error("Failed to check Xtream availability for recording {$this->id}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Get the stream URL for recording
     */
    public function getStreamUrl(): ?string
    {
        if ($this->recordable instanceof Channel) {
            return $this->recordable->url;
        }

        if ($this->recordable instanceof Episode) {
            return $this->recordable->url;
        }

        return null;
    }

    /**
     * Calculate total recording duration including padding
     */
    public function getTotalDurationSeconds(): int
    {
        $start = $this->scheduled_start->timestamp;
        $end = $this->scheduled_end->timestamp;
        $duration = $end - $start;

        return $duration + $this->pre_padding_seconds + $this->post_padding_seconds;
    }

    /**
     * Get the actual start time with pre-padding
     */
    public function getActualStartTime(): \Carbon\Carbon
    {
        return $this->scheduled_start->copy()->subSeconds($this->pre_padding_seconds);
    }

    /**
     * Get the actual end time with post-padding
     */
    public function getActualEndTime(): \Carbon\Carbon
    {
        return $this->scheduled_end->copy()->addSeconds($this->post_padding_seconds);
    }

    /**
     * Mark recording as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'last_error' => $error,
            'actual_end' => now(),
        ]);

        Log::error("Recording {$this->id} failed: {$error}");
    }

    /**
     * Check if recording can be retried
     */
    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->retry_count < $this->max_retries;
    }

    /**
     * Increment retry count
     */
    public function incrementRetry(): void
    {
        $this->increment('retry_count');
        $this->update(['last_retry_at' => now()]);
    }

    /**
     * Scope to get scheduled recordings
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope to get active recordings
     */
    public function scopeRecording($query)
    {
        return $query->where('status', 'recording');
    }

    /**
     * Scope to get failed recordings that can retry
     */
    public function scopeRetryable($query)
    {
        return $query->where('status', 'failed')
            ->whereColumn('retry_count', '<', 'max_retries');
    }
}
