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
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255)
                ->placeholder('example.com'),

            Select::make('status')
                ->options([
                    'unknown' => 'Unknown',
                    'active' => 'Active',
                    'expiring' => 'Expiring',
                    'expired' => 'Expired',
                    'error' => 'Error',
                ])
                ->default('unknown'),

            DateTimePicker::make('expires_at'),
            TextInput::make('registrar')->maxLength(255),
            DateTimePicker::make('last_checked_at'),
            TextInput::make('last_error')->maxLength(255),
            Textarea::make('raw_whois')->rows(12)->columnSpanFull(),
        ]);
    }
}
