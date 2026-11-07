<?php

namespace App\Billing;

/**
 * What one invoice run did.
 */
final readonly class BillingRun
{
    public function __construct(
        public int $created,
        public int $issued,
        public int $overdue,
        public int $reminded,
    ) {}

    public function summary(): string
    {
        return __(':created invoice(s) created, :issued sent, :overdue overdue, :reminded reminder(s) sent.', [
            'created' => $this->created,
            'issued' => $this->issued,
            'overdue' => $this->overdue,
            'reminded' => $this->reminded,
        ]);
    }
}
