<?php

namespace App\Filament\Resources\Domains\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DomainForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('domain')
                ->label('Домен')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255)
                ->placeholder('example.com'),

            Select::make('status')
                ->label('Статус')
                ->options([
                    'unknown' => 'Неизвестно',
                    'active' => 'Активен',
                    'expiring' => 'Скоро истекает',
                    'expired' => 'Истёк',
                    'error' => 'Ошибка',
                ])
                ->default('unknown'),

            DateTimePicker::make('expires_at')
                ->label('Дата истечения'),

            TextInput::make('registrar')
                ->label('Регистратор')
                ->maxLength(255),

            DateTimePicker::make('last_checked_at')
                ->label('Последняя проверка'),

            TextInput::make('last_error')
                ->label('Последняя ошибка')
                ->maxLength(255),

            Textarea::make('raw_whois')
                ->label('Сырые данные WHOIS')
                ->rows(12)
                ->columnSpanFull(),
        ]);
    }
}
