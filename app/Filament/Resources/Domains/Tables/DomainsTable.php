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
                    ->label('Домен')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => 'https://' . $record->domain)
                    ->openUrlInNewTab()
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconPosition('after'),

                TextColumn::make('status')
                    ->badge()
                    ->label('Статус')
                    ->colors([
                        'gray' => 'unknown',
                        'success' => 'active',
                        'warning' => 'expiring',
                        'danger' => ['expired', 'error'],
                    ]),

                TextColumn::make('expires_at')
                    ->label('Дата истечения')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('registrar')
                    ->label('Регистратор')
                    ->searchable()
                    ->limit(30)
                    ->placeholder('-'),

                TextColumn::make('last_checked_at')
                    ->label('Последняя проверка')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'unknown' => 'Неизвестно',
                        'active' => 'Активен',
                        'expiring' => 'Скоро истекает',
                        'expired' => 'Истёк',
                        'error' => 'Ошибка',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('check_now')
                    ->label('Проверить')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Domain $record) {
                        CheckDomainWhoisJob::dispatch($record->id);

                        Notification::make()
                            ->title('Проверка WHOIS поставлена в очередь')
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
                    ->label('Проверить выбранные')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            CheckDomainWhoisJob::dispatch($record->id);
                        }

                        Notification::make()
                            ->title('Выбранные домены отправлены в очередь')
                            ->success()
                            ->send();
                    }),

                DeleteBulkAction::make(),
            ]);
    }
}
