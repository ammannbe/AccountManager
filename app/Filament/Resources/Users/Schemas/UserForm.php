<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('oidc_sub')
                    ->label(__('Identity provider ID'))
                    ->helperText(__('Set by the registration or the import; only change it by hand in the reduced mode.'))
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Toggle::make('is_admin')->label(__('Admin')),
            ]);
    }
}
