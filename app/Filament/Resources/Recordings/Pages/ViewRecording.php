<?php

namespace App\Filament\Resources\Recordings\Pages;

use App\Filament\Resources\Recordings\RecordingResource;
use App\Jobs\StartRecording;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class ViewRecording extends ViewRecord
{
    protected static string $resource = RecordingResource::class;

    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Recording Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Placeholder::make('title')
                                    ->label('Title')
                                    ->content(fn ($record) => $record->title),

                                Placeholder::make('status')
                                    ->label('Status')
                                    ->content(fn ($record) => ucfirst($record->status)),

                                Placeholder::make('recordable_type')
                                    ->label('Type')
                                    ->content(fn ($record) => match ($record->recordable_type) {
                                        'App\\Models\\Channel' => 'Live Channel',
                                        'App\\Models\\Episode' => 'Series Episode',
                                        'App\\Models\\Series' => 'Entire Series',
                                        default => 'Unknown',
                                    }),

                                Placeholder::make('type')
                                    ->label('Schedule Type')
                                    ->content(fn ($record) => ucfirst($record->type)),

                                Placeholder::make('streamProfile.name')
                                    ->label('Stream Profile')
                                    ->content(fn ($record) => $record->streamProfile?->name ?? 'N/A'),

                                Placeholder::make('streamProfile.format')
                                    ->label('Output Format')
                                    ->content(fn ($record) => strtoupper($record->streamProfile?->format ?? 'N/A')),
                            ]),
                    ]),

                Section::make('Schedule')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Placeholder::make('scheduled_start')
                                    ->label('Scheduled Start')
                                    ->content(fn ($record) => $record->scheduled_start->format('Y-m-d H:i:s')),

                                Placeholder::make('scheduled_end')
                                    ->label('Scheduled End')
                                    ->content(fn ($record) => $record->scheduled_end->format('Y-m-d H:i:s')),

                                Placeholder::make('pre_padding_seconds')
                                    ->label('Pre-Padding')
                                    ->content(fn ($record) => "{$record->pre_padding_seconds} seconds"),

                                Placeholder::make('post_padding_seconds')
                                    ->label('Post-Padding')
                                    ->content(fn ($record) => "{$record->post_padding_seconds} seconds"),

                                Placeholder::make('actual_start')
                                    ->label('Actual Start')
                                    ->content(fn ($record) => $record->actual_start?->format('Y-m-d H:i:s') ?? 'Not started'),

                                Placeholder::make('actual_end')
                                    ->label('Actual End')
                                    ->content(fn ($record) => $record->actual_end?->format('Y-m-d H:i:s') ?? 'Not finished'),
                            ]),
                    ]),

                Section::make('Output')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Placeholder::make('output_path')
                                    ->label('File Path')
                                    ->content(fn ($record) => $record->output_path ?? 'Not yet recorded')
                                    ->columnSpanFull(),

                                Placeholder::make('file_size_bytes')
                                    ->label('File Size')
                                    ->content(fn ($record) => $record->file_size_bytes ? Number::fileSize($record->file_size_bytes) : 'N/A'),

                                Placeholder::make('duration_seconds')
                                    ->label('Duration')
                                    ->content(fn ($record) => $record->duration_seconds ? gmdate('H:i:s', $record->duration_seconds) : 'N/A'),

                                Placeholder::make('segments')
                                    ->label('Segments')
                                    ->content(fn ($record) => $record->segments()->count()),
                            ]),
                    ]),

                Section::make('Error Information')
                    ->schema([
                        Placeholder::make('last_error')
                            ->label('Last Error')
                            ->content(fn ($record) => $record->last_error ?? 'No errors')
                            ->columnSpanFull(),

                        Grid::make(2)
                            ->schema([
                                Placeholder::make('retry_count')
                                    ->label('Retry Count')
                                    ->content(fn ($record) => $record->retry_count),

                                Placeholder::make('max_retries')
                                    ->label('Max Retries')
                                    ->content(fn ($record) => $record->max_retries),

                                Placeholder::make('last_retry_at')
                                    ->label('Last Retry')
                                    ->content(fn ($record) => $record->last_retry_at?->format('Y-m-d H:i:s') ?? 'Never retried'),
                            ]),
                    ])
                    ->visible(fn ($record) => $record->status === 'failed' || $record->retry_count > 0),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('start_now')
                ->label('Start Now')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['status' => 'scheduled']);
                    StartRecording::dispatch($this->record);

                    $this->notify('success', 'Recording started');
                })
                ->visible(fn () => $this->record->status === 'scheduled'),

            Actions\Action::make('cancel')
                ->label('Cancel Recording')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update([
                        'status' => 'cancelled',
                        'last_error' => 'Cancelled by user',
                    ]);

                    $this->notify('success', 'Recording cancelled');
                })
                ->visible(fn () => in_array($this->record->status, ['scheduled', 'recording'])),

            Actions\Action::make('retry')
                ->label('Retry Recording')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->incrementRetry();
                    $this->record->update(['status' => 'scheduled']);
                    StartRecording::dispatch($this->record);

                    $this->notify('success', 'Recording retry scheduled');
                })
                ->visible(fn () => $this->record->status === 'failed' && $this->record->canRetry()),

            Actions\EditAction::make()
                ->visible(fn () => $this->record->status === 'scheduled'),

            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->status !== 'recording'),
        ];
    }
}
