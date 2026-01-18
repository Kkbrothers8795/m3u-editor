<?php

namespace App\Filament\Tables;

use App\Models\Episode;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecordableEpisodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                // $arguments = $table->getArguments();

                return $query->where('user_id', auth()->id());
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->query(
                Episode::query()
                    ->with(['series.playlist', 'series.category'])
            )
            ->columns([
                TextColumn::make('title')
                    ->label('Episode')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('series.name')
                    ->label('Series')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('series.playlist.name')
                    ->label('Playlist')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('season')
                    ->label('Season #')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('episode_num')
                    ->label('Ep #')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('season')
                    ->label('Season')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('playlist')
                    ->label('Playlist')
                    ->options(function () {
                        return \App\Models\Playlist::query()
                            ->where('user_id', auth()->id())
                            ->whereHas('series')
                            ->pluck('name', 'id');
                    })
                    ->query(function ($query, $state) {
                        if ($state['value'] ?? null) {
                            $query->whereHas('series.playlist', function ($q) use ($state) {
                                $q->where('id', $state['value']);
                            });
                        }
                    })
                    ->searchable()
                    ->preload(),

                SelectFilter::make('series_id')
                    ->label('Series')
                    ->relationship('series', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name} [{$record->playlist->name}]")
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('episode_num');
    }
}
