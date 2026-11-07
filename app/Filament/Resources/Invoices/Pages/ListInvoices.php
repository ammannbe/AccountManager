<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Billing\BillingConfig;
use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getSubheading(): ?string
    {
        $config = app(BillingConfig::class);

        if (! $config->enabled()) {
            return (string) __('Billing is off until BILLING_CREDITOR_IBAN is set; no invoices are created.');
        }

        $errors = $config->errors();

        return $errors === [] ? null : (string) __('Billing is not configured correctly: :errors', ['errors' => implode(' ', $errors)]);
    }

    protected function getHeaderActions(): array
    {
        return [
            InvoiceActions::run(),
        ];
    }
}
