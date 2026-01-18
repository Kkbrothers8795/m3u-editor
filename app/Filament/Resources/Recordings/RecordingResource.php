<?php

namespace App\Filament\Resources\Recordings;

use App\Filament\Resources\Recordings\Pages\CreateRecording;
use App\Filament\Resources\Recordings\Pages\EditRecording;
use App\Filament\Resources\Recordings\Pages\ListRecordings;
use App\Filament\Resources\Recordings\Pages\ViewRecording;
use App\Filament\Tables\RecordableChannelsTable;
use App\Filament\Tables\RecordableEpisodesTable;
use App\Filament\Tables\RecordableSeriesTable;
use App\Jobs\StartRecording;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Recording;
use App\Models\Series;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup as ActionsBulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction as ActionsDeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class RecordingResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Recording::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|\UnitEnum|null $navigationGroup = 'Proxy';

    protected static ?int $navigationSort = 8;

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'type'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('recordable_type')
                    ->label('Record Type')
                    ->options([
                        Channel::class => 'Live Channel',
                        Episode::class => 'Series Episode',
                        Series::class => 'Entire Series',
                    ])
                    ->disabledOn(['edit'])
                    ->required()
                    ->live()
                    ->columnSpanFull(),

                ModalTableSelect::make('recordable_id')
                    ->label(fn (Get $get) => match ($get('recordable_type')) {
                        Channel::class => 'Channel',
                        Episode::class => 'Episode',
                        Series::class => 'Series',
                        default => 'Item',
                    })
                    ->tableConfiguration(function (Get $get) {
                        return match ($get('recordable_type')) {
                            Channel::class => RecordableChannelsTable::class,
                            Episode::class => RecordableEpisodesTable::class,
                            Series::class => RecordableSeriesTable::class,
                            default => null,
                        };
                    })
                    ->selectAction(
                        fn ($action, Get $get) => $action
                            ->label(match ($get('recordable_type')) {
                                Channel::class => 'Select channel',
                                Episode::class => 'Select episode',
                                Series::class => 'Select series',
                                default => 'Select item',
                            })
                            ->modalHeading(match ($get('recordable_type')) {
                                Channel::class => 'Search channels',
                                Episode::class => 'Search episodes',
                                Series::class => 'Search series',
                                default => 'Search items',
                            })
                            ->modalSubmitActionLabel('Confirm selection')
                            ->button()
                    )
                    ->getOptionLabelUsing(function ($value, Get $get) {
                        $type = $get('recordable_type');
                        if (! $type || ! $value) {
                            return $value;
                        }

                        $record = match ($type) {
                            Channel::class => Channel::find($value),
                            Episode::class => Episode::find($value),
                            Series::class => Series::find($value),
                            default => null,
                        };

                        // Channel and Episode use 'title', Series uses 'name'
                        return match ($type) {
                            Series::class => $record?->name ?? $value,
                            default => $record?->title ?? $value,
                        };
                    })
                    ->getOptionLabelsUsing(function (array $values, Get $get) {
                        $type = $get('recordable_type');
                        if (! $type || empty($values)) {
                            return [];
                        }

                        return match ($type) {
                            Channel::class => Channel::whereIn('id', $values)->pluck('title', 'id')->toArray(),
                            Episode::class => Episode::whereIn('id', $values)->pluck('title', 'id')->toArray(),
                            Series::class => Series::whereIn('id', $values)->pluck('name', 'id')->toArray(),
                            default => [],
                        };
                    })
                    ->disabled(fn (Get $get) => ! $get('recordable_type'))
                    ->required()
                    ->columnSpanFull()
                    ->helperText(fn (Get $get) => ! $get('recordable_type') ? 'Please select a record type first' : null),

                TextInput::make('title')
                    ->label('Recording Title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('A descriptive name for this recording'),

                Select::make('type')
                    ->label('Recording Type')
                    ->options([
                        'once' => 'One Time',
                        'series' => 'Series (All Episodes)',
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                    ])
                    ->default('once')
                    ->required()
                    ->columnSpan(1),

                Select::make('stream_profile_id')
                    ->label('Stream Profile')
                    ->relationship('streamProfile', 'name')
                    ->required()
                    ->helperText('Defines the output format and quality')
                    ->columnSpan(1),

                Toggle::make('start_now')
                    ->label('Start Recording Immediately')
                    ->helperText('Start recording as soon as this is saved')
                    ->default(false)
                    ->live()
                    ->visible(fn (Get $get) => $get('recordable_type') === Channel::class)
                    ->columnSpan(1),

                DateTimePicker::make('scheduled_start')
                    ->label('Start Time')
                    ->required(fn (Get $get) => ! $get('start_now'))
                    ->disabled(fn (Get $get) => $get('start_now'))
                    ->default(fn (Get $get) => $get('start_now') ? now() : null)
                    ->seconds(false)
                    ->visible(fn (Get $get) => $get('recordable_type') === Channel::class)
                    ->columnSpan(1)
                    ->helperText(fn (Get $get) => $get('start_now') ? 'Will start immediately' : 'When to start recording'),

                DateTimePicker::make('scheduled_end')
                    ->label('End Time')
                    ->required()
                    ->seconds(false)
                    ->visible(fn (Get $get) => $get('recordable_type') === Channel::class)
                    ->columnSpan(1)
                    ->helperText('When to stop recording'),

                TextInput::make('pre_padding_seconds')
                    ->label('Pre-Padding (seconds)')
                    ->numeric()
                    ->default(60)
                    ->helperText('Start recording this many seconds before scheduled start')
                    ->visible(fn (Get $get) => $get('recordable_type') === Channel::class && ! $get('start_now'))
                    ->columnSpan(1),

                TextInput::make('post_padding_seconds')
                    ->label('Post-Padding (seconds)')
                    ->numeric()
                    ->default(120)
                    ->helperText('Continue recording this many seconds after scheduled end')
                    ->visible(fn (Get $get) => $get('recordable_type') === Channel::class)
                    ->columnSpan(1),

                TextInput::make('max_retries')
                    ->label('Max Retries')
                    ->numeric()
                    ->default(3)
                    ->helperText('Number of times to retry if recording fails')
                    ->columnSpan(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->defaultSort('scheduled_start', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('recordable_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        Channel::class => 'Channel',
                        Episode::class => 'Episode',
                        Series::class => 'Series',
                        default => 'Unknown',
                    })
                    ->color(fn ($state) => match ($state) {
                        Channel::class => 'info',
                        Episode::class => 'success',
                        Series::class => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'scheduled' => 'gray',
                        'recording' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('scheduled_start')
                    ->label('Start')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('scheduled_end')
                    ->label('End')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn ($state) => $state ? gmdate('H:i:s', $state) : '-')
                    ->toggleable(),

                TextColumn::make('file_size_bytes')
                    ->label('File Size')
                    ->formatStateUsing(fn ($state) => $state ? Number::fileSize($state) : '-')
                    ->toggleable(),

                TextColumn::make('streamProfile.name')
                    ->label('Profile')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('retry_count')
                    ->label('Retries')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'scheduled' => 'Scheduled',
                        'recording' => 'Recording',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),

                SelectFilter::make('recordable_type')
                    ->label('Type')
                    ->options([
                        Channel::class => 'Channel',
                        Episode::class => 'Episode',
                        Series::class => 'Series',
                    ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('start_now')
                        ->label('Start Now')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($record) {
                            $record->update(['status' => 'scheduled']);
                            StartRecording::dispatch($record);

                            $this->notify('success', 'Recording started');
                        })
                        ->visible(fn ($record) => $record->status === 'scheduled'),

                    Action::make('retry')
                        ->label('Retry Recording')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($record) {
                            $record->incrementRetry();
                            $record->update(['status' => 'scheduled']);
                            StartRecording::dispatch($record);

                            $this->notify('success', 'Recording retry scheduled');
                        })
                        ->visible(fn ($record) => $record->status === 'failed' && $record->canRetry()),

                    Action::make('cancel')
                        ->label('Cancel Recording')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($record) {
                            $record->update([
                                'status' => 'cancelled',
                                'last_error' => 'Cancelled by user',
                            ]);

                            $this->notify('success', 'Recording cancelled');
                        })
                        ->visible(fn ($record) => in_array($record->status, ['scheduled', 'recording'])),

                    DeleteAction::make()
                        ->hidden(fn (Recording $record) => $record->status === 'recording'),
                ])->button()->hiddenLabel()->size('sm'),

                EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm')
                    ->disabled(fn (Recording $record) => in_array($record->status, ['recording', 'completed'])),

                ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                ActionsBulkActionGroup::make([
                    ActionsDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecordings::route('/'),
            // 'create' => CreateRecording::route('/create'),
            'view' => ViewRecording::route('/{record}'),
            // 'edit' => EditRecording::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::recording()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
