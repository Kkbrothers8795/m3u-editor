# Recording & DVR Feature

This document describes the recording/DVR feature implementation.

## Overview

The DVR feature allows automatic recording of live channels and series episodes using the proxy transcoding system. Recordings are managed through Laravel jobs and can be scheduled for one-time or recurring recordings.

## Architecture

### Database Tables

- **recordings**: Main table storing recording schedules and metadata
- **recording_segments**: Stores individual recording segments (for interrupted recordings)

### Models

- `Recording`: Main recording model with relationships to recordables (Channel, Episode, Series)
- `RecordingSegment`: Individual recording segments

### Services

- `RecordingService`: Core service handling file operations, segment merging, STRM file creation

### Jobs

- `ScheduleRecordings`: Runs every minute to check for upcoming recordings
- `StartRecording`: Starts the actual recording process
- `ProcessRecording`: Post-processing (merging segments, creating STRM files)
- `MonitorRecordings`: Monitors active recordings for issues

## Configuration

### Environment Variables

Add to `.env`:

```env
# Recording storage path (defaults to storage/app/recordings)
RECORDINGS_PATH=/path/to/recordings
```

### Stream Profiles Required

Recordings **require** a Stream Profile to be assigned. The profile defines:
- Output format (TS, MP4, MKV, etc.)
- Video/audio codecs and quality
- Transcoding parameters

## Usage

### Creating a Recording

1. Navigate to **Recordings** in the admin panel
2. Click **Create Recording**
3. Select:
   - Record type (Channel, Episode, or Series)
   - The specific item to record
   - Stream profile for output format
   - Schedule (start/end times)
   - Pre/post padding (buffer before/after)
4. Save

### Recording Types

- **Once**: Single recording at scheduled time
- **Series**: Record all episodes of a series (future feature)
- **Daily**: Repeat daily at same time (future feature)
- **Weekly**: Repeat weekly (future feature)

### Monitoring

- Active recordings show in the recordings list with "Recording" status
- Failed recordings can be manually retried
- Completed recordings show file size and duration

## Technical Details

### Connection Availability

Before starting a recording, the system checks:
1. XtreamAPI connection availability (if applicable)
2. Profile-based connection pools (if enabled)
3. Disk space availability

### Segment Recording

Recordings use a segmented approach:
- Each recording creates numbered segments
- If interrupted (power loss, reboot), segments are preserved
- On completion, segments are merged using FFmpeg
- Failed segments can be retried

### Failure Handling

- Configurable retry count (default: 3)
- Exponential backoff for retries
- Preserves partial recordings
- Detailed error logging

### Disk Space Management

- Pre-recording space check based on estimated size
- Configurable cleanup of old recordings
- Warning when disk space is low (<5GB)
- Auto-cancel new recordings if critically low (<1GB)

## Scheduler Tasks

The following scheduled tasks are automatically configured:

```php
// Check for recordings to start (every minute)
Schedule::job(new \App\Jobs\ScheduleRecordings)
    ->everyMinute()
    ->withoutOverlapping();

// Monitor active recordings (every 5 minutes)
Schedule::job(new \App\Jobs\MonitorRecordings)
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Cleanup old recordings (daily)
Schedule::command('recordings:cleanup --days=30 --force')
    ->daily()
    ->withoutOverlapping();
```

## API Integration

### Proxy Stream Creation

Recordings create transcoded streams via the m3u-proxy API:

```php
POST /transcode
{
    "url": "stream_url",
    "profile": "profile_template_or_name",
    "profile_variables": {...},
    "metadata": {
        "recording_id": 123,
        "type": "dvr_recording"
    }
}
```

### FFmpeg Recording

The stream is downloaded using FFmpeg:

```bash
ffmpeg -i {proxy_stream_url} -c copy -t {duration} {output_file}
```

## STRM File Generation

After recording completes, a .strm file can be created pointing to the recorded file for media server integration (currently disabled by default).

## File Organization

Recordings are organized by:
```
recordings/
  user_{id}/
    channels/
      recording_{id}/
        segment_001.ts
        segment_002.ts
        final_output.ts
    series/
      recording_{id}/
        ...
```

## Future Enhancements

- [ ] Series-based recording (record all episodes automatically)
- [ ] EPG integration for automatic scheduling
- [ ] Remote storage support (S3, NFS)
- [ ] Real-time recording progress monitoring
- [ ] Post-processing hooks (notification, webhook)
- [ ] Commercial detection/removal
- [ ] Automatic STRM file creation option
