<?php

namespace App\Filament\Resources\Recordings;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Recording;
use App\Models\Series;
use App\Traits\HasUserFiltering;
use Filament\Actions\BulkActionGroup as ActionsBulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction as ActionsDeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Resources\Resource;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Playlist';

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
                    ->required()
                    ->live()
                    ->columnSpanFull(),

                Select::make('recordable_id')
                    ->label(fn (Get $get) => match ($get('recordable_type')) {
                        Channel::class => 'Channel',
                        Episode::class => 'Episode',
                        Series::class => 'Series',
                        default => 'Item',
                    })
                    ->options(function (Get $get) {
                        $type = $get('recordable_type');

                        return match ($type) {
                            Channel::class => Channel::query()
                                ->where('user_id', auth()->id())
                                ->where('enabled', true)
                                ->orderBy('title')
                                ->pluck('title', 'id'),
                            Episode::class => Episode::query()
                                ->whereHas('series', function ($query) {
                                    $query->where('user_id', auth()->id());
                                })
                                ->orderBy('title')
                                ->pluck('title', 'id'),
                            Series::class => Series::query()
                                ->where('user_id', auth()->id())
                                ->where('enabled', true)
                                ->orderBy('title')
                                ->pluck('title', 'id'),
                            default => [],
                        };
                    })
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),

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

                DateTimePicker::make('scheduled_start')
                    ->label('Start Time')
                    ->required()
                    ->seconds(false)
                    ->columnSpan(1),

                DateTimePicker::make('scheduled_end')
                    ->label('End Time')
                    ->required()
                    ->seconds(false)
                    ->columnSpan(1),

                TextInput::make('pre_padding_seconds')
                    ->label('Pre-Padding (seconds)')
                    ->numeric()
                    ->default(60)
                    ->helperText('Start recording this many seconds before scheduled start')
                    ->columnSpan(1),

                TextInput::make('post_padding_seconds')
                    ->label('Post-Padding (seconds)')
                    ->numeric()
                    ->default(120)
                    ->helperText('Continue recording this many seconds after scheduled end')
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
                ViewAction::make(),
                EditAction::make()
                    ->hidden(fn (Recording $record) => in_array($record->status, ['recording', 'completed'])),
                DeleteAction::make()
                    ->hidden(fn (Recording $record) => $record->status === 'recording'),
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
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
            'index' => \App\Filament\Resources\Recordings\Pages\ListRecordings::route('/'),
            'create' => \App\Filament\Resources\Recordings\Pages\CreateRecording::route('/create'),
            'view' => \App\Filament\Resources\Recordings\Pages\ViewRecording::route('/{record}'),
            'edit' => \App\Filament\Resources\Recordings\Pages\EditRecording::route('/{record}/edit'),
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
