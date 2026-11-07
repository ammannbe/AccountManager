<?php

namespace App\Billing;

use App\Enums\ReferenceType;
use App\Models\Invoice;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;
use Sprain\SwissQrBill\Reference\RfCreditorReferenceGenerator;

/**
 * Swiss QR-bill data of an invoice (sprain/swiss-qr-bill). The payment part itself is laid out in
 * resources/views/invoices/payment-part.blade.php and rendered with the rest of the PDF.
 */
class QrBills
{
    /**
     * The only currencies of the Swiss QR-bill; invoices in other currencies have no payment part.
     */
    public const CURRENCIES = ['CHF', 'EUR'];

    /**
     * Reference of an invoice, built from year and sequence (unique), for matching payments later
     * (camt.054). QRR: 27 digits with check digit, optionally after the bank's customer id;
     * SCOR: RF creditor reference (ISO 11649); NON: none.
     */
    public static function reference(ReferenceType $type, int $year, int $sequence, mixed $customerId = null): ?string
    {
        $number = $year.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

        return match ($type) {
            ReferenceType::Qrr => QrPaymentReferenceGenerator::generate(filled($customerId) ? (string) $customerId : null, $number),
            ReferenceType::Scor => RfCreditorReferenceGenerator::generate($number),
            ReferenceType::Non => null,
        };
    }

    public static function supports(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    /**
     * The QR-bill of an invoice, or null for currencies the QR-bill does not support.
     */
    public function forInvoice(Invoice $invoice): ?QrBill
    {
        if (! self::supports($invoice->currency)) {
            return null;
        }

        return $this->make(
            $invoice->creditor,
            $invoice->recipient,
            $invoice->total_cents,
            $invoice->currency,
            $invoice->reference_type,
            $invoice->reference,
            __('Invoice :number', ['number' => $invoice->number]),
        );
    }

    /**
     * @param  array{name: string, street: ?string, postal_code: ?string, city: ?string, country: ?string, iban: string}  $creditor
     * @param  array{name: string, street: ?string, postal_code: ?string, city: ?string, country: ?string}|null  $debtor
     */
    public function make(array $creditor, ?array $debtor, int $amountCents, string $currency, ReferenceType $type, ?string $reference, ?string $message): QrBill
    {
        $bill = QrBill::create()
            ->setCreditor(self::address($creditor) ?? StructuredAddress::createWithoutStreet($creditor['name'], '', '', ''))
            ->setCreditorInformation(CreditorInformation::create($creditor['iban']))
            ->setPaymentAmountInformation(PaymentAmountInformation::create($currency, $amountCents / 100))
            ->setPaymentReference(PaymentReference::create($type->value, $reference));

        // An incomplete or unprintable debtor address leaves the field empty to fill in by hand
        // (the debtor is optional), instead of making the whole bill invalid.
        $debtorAddress = $debtor !== null ? self::address($debtor) : null;

        if ($debtorAddress !== null && $debtorAddress->isValid()) {
            $bill->setUltimateDebtor($debtorAddress);
        }

        if ($message !== null && $message !== '') {
            $bill->setAdditionalInformation(AdditionalInformation::create(mb_substr($message, 0, 140)));
        }

        return $bill;
    }

    /**
     * @param  array{name: string, street: ?string, postal_code: ?string, city: ?string, country: ?string}  $address
     */
    private static function address(array $address): ?StructuredAddress
    {
        if (blank($address['postal_code']) || blank($address['city']) || blank($address['country'])) {
            return null;
        }

        return blank($address['street'])
            ? StructuredAddress::createWithoutStreet($address['name'], (string) $address['postal_code'], (string) $address['city'], (string) $address['country'])
            : StructuredAddress::createWithStreet($address['name'], (string) $address['street'], null, (string) $address['postal_code'], (string) $address['city'], (string) $address['country']);
    }
}
