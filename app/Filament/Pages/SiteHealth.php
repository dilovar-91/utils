<?php

namespace App\Filament\Pages;

use App\Jobs\CheckSiteHealthJob;
use App\Models\Domain;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class SiteHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Health сайтов';

    protected static ?string $title = 'Проверка сайтов';

    protected static ?string $slug = 'site-health';

    protected static ?int $navigationSort = 2;

    protected ?string $subheading = 'Отметьте сайты, которые нужно проверять на доступность.';

    public static function getNavigationBadge(): ?string
    {
        $count = Domain::query()
            ->healthCheckEnabled()
            ->where('health_status', 'down')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Domain::query())
            ->defaultSort('domain')
            ->poll('15s')
            ->columns([
                ToggleColumn::make('health_check_enabled')
                    ->label('Проверять')
                    ->onColor('success')
                    ->offColor('gray'),

                TextColumn::make('domain')
                    ->label('Сайт')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Domain $record): ?string => $record->health_url)
                    ->url(fn (Domain $record): string => $record->getHealthCheckUrl())
                    ->openUrlInNewTab()
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconPosition('after'),

                TextColumn::make('health_status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'up' => 'Работает',
                        'down' => 'Недоступен',
                        default => 'Неизвестно',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'up' => 'success',
                        'down' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Domain $record): ?string => $record->health_curl_fail_count > 0
                        ? "cURL попытка {$record->health_curl_fail_count}/3"
                        : null)
                    ->sortable(),

                TextColumn::make('health_status_code')
                    ->label('HTTP')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('health_response_time_ms')
                    ->label('Время ответа')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : "{$state} мс")
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('last_health_checked_at')
                    ->label('Последняя проверка')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('last_health_error')
                    ->label('Ошибка')
                    ->limit(60)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('health_check_enabled')
                    ->label('Проверка')
                    ->trueLabel('Включена')
                    ->falseLabel('Выключена')
                    ->placeholder('Все'),

                SelectFilter::make('health_status')
                    ->label('Статус')
                    ->options([
                        'unknown' => 'Неизвестно',
                        'up' => 'Работает',
                        'down' => 'Недоступен',
                    ]),
            ])
            ->recordActions([
                Action::make('check_now')
                    ->label('Проверить')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Domain $record) {
                        CheckSiteHealthJob::dispatchSync($record->id);

                        Notification::make()
                            ->title("Проверка {$record->domain} завершена")
                            ->success()
                            ->send();
                    }),

                Action::make('edit_url')
                    ->label('URL')
                    ->icon('heroicon-o-link')
                    ->schema([
                        TextInput::make('health_url')
                            ->label('URL проверки')
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://example.com/health')
                            ->helperText('Если пусто, проверяется https://домен'),
                    ])
                    ->fillForm(fn (Domain $record): array => [
                        'health_url' => $record->health_url,
                    ])
                    ->action(function (Domain $record, array $data): void {
                        $record->update([
                            'health_url' => filled($data['health_url'] ?? null) ? $data['health_url'] : null,
                        ]);
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('enable_health_check')
                    ->label('Включить проверку')
                    ->icon('heroicon-o-check')
                    ->action(function (Collection $records): void {
                        $records->each->update(['health_check_enabled' => true]);

                        Notification::make()
                            ->title('Проверка включена для выбранных сайтов')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('disable_health_check')
                    ->label('Выключить проверку')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->action(function (Collection $records): void {
                        $records->each->update(['health_check_enabled' => false]);

                        Notification::make()
                            ->title('Проверка выключена для выбранных сайтов')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('bulk_check')
                    ->label('Проверить выбранные')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            CheckSiteHealthJob::dispatch($record->id);
                        }

                        Notification::make()
                            ->title('Выбранные сайты отправлены в очередь проверки')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check_enabled')
                ->label('Проверить включённые')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $ids = Domain::query()
                        ->healthCheckEnabled()
                        ->pluck('id');

                    if ($ids->isEmpty()) {
                        Notification::make()
                            ->title('Нет сайтов с включённой проверкой')
                            ->warning()
                            ->send();

                        return;
                    }

                    foreach ($ids as $id) {
                        CheckSiteHealthJob::dispatch($id);
                    }

                    Notification::make()
                        ->title("В очередь отправлено сайтов: {$ids->count()}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
