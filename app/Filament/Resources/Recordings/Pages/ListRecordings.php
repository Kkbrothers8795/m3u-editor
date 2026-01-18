<?php

namespace App\Filament\Resources\Recordings\Pages;

use App\Filament\Resources\Recordings\RecordingResource;
use App\Models\Episode;
use App\Models\Series;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecordings extends ListRecords
{
    protected static string $resource = RecordingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['user_id'] = auth()->id();
                    $data['status'] = 'scheduled';

                    // Handle "start now" for live channels
                    if (isset($data['start_now']) && $data['start_now']) {
                        $data['scheduled_start'] = now();
                        unset($data['start_now']);
                    }

                    // For VOD content (Episodes/Series), set immediate start times
                    // since they're not time-based like live channels
                    if (in_array($data['recordable_type'], [Episode::class, Series::class])) {
                        $data['scheduled_start'] = now();
                        $data['scheduled_end'] = now()->addHours(24); // 24 hour window to complete
                        $data['pre_padding_seconds'] = 0;
                        $data['post_padding_seconds'] = 0;
                    }

                    return $data;
                }),
        ];
    }
}
