<?php

namespace App\Filament\Resources\Domains\Tables;

use App\Jobs\CheckDomainWhoisJob;
use App\Models\Domain;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->latest())
            ->columns([
                TextColumn::make('domain')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'unknown',
                        'success' => 'active',
                        'warning' => 'expiring',
                        'danger' => ['expired', 'error'],
                    ]),

                TextColumn::make('expires_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('registrar')
                    ->searchable()
                    ->limit(30)
                    ->placeholder('-'),

                TextColumn::make('last_checked_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'unknown' => 'Unknown',
                        'active' => 'Active',
                        'expiring' => 'Expiring',
                        'expired' => 'Expired',
                        'error' => 'Error',
                    ]),
            ])
            ->recordActions([
               EditAction::make(),

                Action::make('check_now')
                    ->label('Check now')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Domain $record) {
                        CheckDomainWhoisJob::dispatch($record->id);

                        Notification::make()
                            ->title('WHOIS check queued')
                            ->success()
                            ->send();
                    }),

               Action::make('show_whois')
                    ->label('WHOIS')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn (Domain $record) => "WHOIS: {$record->domain}")
                    ->modalContent(fn (Domain $record) => view('filament.domain-whois', [
                        'whois' => $record->raw_whois,
                    ]))
                    ->modalSubmitAction(false),
            ])
            ->toolbarActions([
                BulkAction::make('bulk_check')
                    ->label('Check selected')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            CheckDomainWhoisJob::dispatch($record->id);
                        }

                        Notification::make()
                            ->title('Selected domains queued')
                            ->success()
                            ->send();
                    }),

                DeleteBulkAction::make(),
            ]);
    }
}
