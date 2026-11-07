<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Billing\Money;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The user's invoices (read-only here; the actions are on the invoice page).
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Invoices');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number')),
                TextColumn::make('total_cents')->label(__('Total'))->state(fn (Invoice $record): string => Money::format($record->total_cents, $record->currency)),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('issued_at')->label(__('Invoice date'))->date('d.m.Y'),
                TextColumn::make('due_at')->label(__('Payable by'))->date('d.m.Y'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open invoice'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
