<?php

namespace App\Billing;

use App\Enums\ReferenceType;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Throwable;

/**
 * The creditor and the billing settings from config/billing.php. Billing is off (no invoice runs)
 * until the creditor's IBAN is set.
 */
class BillingConfig
{
    public function enabled(): bool
    {
        return $this->iban() !== '';
    }

    /**
     * IBAN without spaces, upper case.
     */
    public function iban(): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', (string) config('billing.creditor.iban')));
    }

    public function hasQrIban(): bool
    {
        return $this->iban() !== '' && CreditorInformation::create($this->iban())->containsQrIban();
    }

    /**
     * The configured type, otherwise QRR with a QR-IBAN and SCOR with a normal IBAN.
     */
    public function referenceType(): ReferenceType
    {
        $configured = strtoupper(trim((string) config('billing.reference_type')));

        return ReferenceType::tryFrom($configured) ?? ($this->hasQrIban() ? ReferenceType::Qrr : ReferenceType::Scor);
    }

    public function currency(): string
    {
        return strtoupper((string) config('billing.currency', 'CHF'));
    }

    public function paymentTermsDays(): int
    {
        return max(0, (int) config('billing.payment_terms_days', 30));
    }

    public function reminderDays(): ?int
    {
        $days = config('billing.reminder_days');

        return $days === null ? null : max(0, (int) $days);
    }

    /**
     * Creditor at the time of an invoice (stored with it).
     *
     * @return array{name: string, street: ?string, postal_code: ?string, city: ?string, country: ?string, iban: string, email: ?string, website: ?string, vat_number: ?string, vat_note: ?string}
     */
    public function creditor(): array
    {
        $value = fn (string $key): ?string => filled(config($key)) ? trim((string) config($key)) : null;

        return [
            'name' => (string) $value('billing.creditor.name'),
            'street' => $value('billing.creditor.street'),
            'postal_code' => $value('billing.creditor.postal_code'),
            'city' => $value('billing.creditor.city'),
            'country' => strtoupper((string) ($value('billing.creditor.country') ?? 'CH')),
            'iban' => $this->iban(),
            'email' => $value('billing.creditor.email'),
            'website' => $value('billing.creditor.website'),
            'vat_number' => $value('billing.creditor.vat_number'),
            'vat_note' => $value('billing.vat_note'),
        ];
    }

    /**
     * Problems that keep the invoice run from starting (empty when everything is fine): missing
     * creditor fields, an invalid IBAN or a reference type that does not fit the IBAN.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        if (! $this->enabled()) {
            return ['BILLING_CREDITOR_IBAN is not set.'];
        }

        $creditor = $this->creditor();
        $errors = [];

        foreach (['name' => 'BILLING_CREDITOR_NAME', 'postal_code' => 'BILLING_CREDITOR_POSTAL_CODE', 'city' => 'BILLING_CREDITOR_CITY'] as $field => $variable) {
            if ($creditor[$field] === null || $creditor[$field] === '') {
                $errors[] = "{$variable} is not set.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $type = $this->referenceType();

        try {
            $reference = QrBills::reference($type, (int) now()->year, 1, config('billing.qr_customer_id'));
        } catch (Throwable) {
            return ['BILLING_QR_CUSTOMER_ID must be digits only (at most 11).'];
        }

        $bill = app(QrBills::class)->make($creditor, null, 100, $this->currency() === 'EUR' ? 'EUR' : 'CHF', $type, $reference, null);

        foreach ($bill->getViolations() as $violation) {
            $errors[] = trim("{$violation->getPropertyPath()}: {$violation->getMessage()}", ': ');
        }

        return $errors;
    }
}
