<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Billing\BillingAddress;
use App\Entitlements\Entitlements;
use App\Models\User;
use App\Rules\QrBillText;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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
                Section::make(__('Billing address'))
                    ->description(__('For the invoices and the payment slip; the user can also change it in the profile.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('billing_name')->label(__('Name or company'))->helperText(__('Empty: the name above.'))->maxLength(70)->rules([new QrBillText]),
                        TextInput::make('billing_street')->label(__('Street and number'))->maxLength(70)->rules([new QrBillText]),
                        TextInput::make('billing_postal_code')->label(__('Postal code'))->maxLength(16)->requiredWith('billing_street,billing_city')->rules([new QrBillText]),
                        TextInput::make('billing_city')->label(__('City'))->maxLength(35)->requiredWith('billing_street,billing_postal_code')->rules([new QrBillText]),
                        Select::make('billing_country')
                            ->label(__('Country'))
                            ->options(fn (): array => BillingAddress::countries())
                            ->searchable()
                            ->requiredWith('billing_postal_code,billing_city'),
                    ]),
                Section::make(__('Effective entitlements'))
                    ->description(__('What the apps get: features of active plans and direct grants, only while the account at the service is active.'))
                    ->visibleOn('edit')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('entitlements')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder(__('No services yet.'))
                            ->state(fn (?User $record): array => $record ? self::entitlements($record) : []),
                    ]),
            ]);
    }

    /**
     * One line per service with an account: status, admin flag and features.
     *
     * @return list<string>
     */
    private static function entitlements(User $user): array
    {
        $entitlements = app(Entitlements::class);
        $lines = [];

        foreach ($user->serviceAccounts()->with('service')->get() as $account) {
            $snapshot = $entitlements->compute($user, $account->service, $account);
            $parts = [$account->status->getLabel()];

            if ($snapshot['admin']) {
                $parts[] = __('Admin');
            }

            $features = $snapshot['features'] === [] ? __('no features') : implode(', ', $snapshot['features']);
            $lines[] = "{$account->service->name} (".implode(', ', $parts)."): {$features}";
        }

        return $lines;
    }
}
