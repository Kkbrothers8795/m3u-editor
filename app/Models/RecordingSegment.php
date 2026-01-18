<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordingSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'recording_id',
        'segment_number',
        'file_path',
        'started_at',
        'ended_at',
        'file_size_bytes',
        'status',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'file_size_bytes' => 'integer',
        'segment_number' => 'integer',
    ];

    /**
     * Get the parent recording
     */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }

    /**
     * Check if the segment file exists
     */
    public function fileExists(): bool
    {
        return file_exists($this->file_path);
    }

    /**
     * Get the actual file size
     */
    public function getActualFileSize(): ?int
    {
        if (! $this->fileExists()) {
            return null;
        }

        return filesize($this->file_path);
    }

    /**
     * Update file size from disk
     */
    public function updateFileSize(): void
    {
        if ($size = $this->getActualFileSize()) {
            $this->update(['file_size_bytes' => $size]);
        }
    }

    /**
     * Mark segment as completed
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);
        $this->updateFileSize();
    }

    /**
     * Mark segment as failed
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'ended_at' => now(),
        ]);
    }

    /**
     * Get segment duration in seconds
     */
    public function getDurationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->ended_at) {
            return null;
        }

        return $this->ended_at->diffInSeconds($this->started_at);
    }
}
