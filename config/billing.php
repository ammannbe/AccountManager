<?php

$reminderDays = env('BILLING_REMINDER_DAYS', 10);

return [

    /*
    |--------------------------------------------------------------------------
    | Creditor
    |--------------------------------------------------------------------------
    |
    | Who receives the payments (printed on the invoice and in the Swiss QR-bill).
    | Billing stays off (no invoice runs) until the IBAN is set.
    |
    */

    'creditor' => [
        'name' => env('BILLING_CREDITOR_NAME'),
        // Street and house number.
        'street' => env('BILLING_CREDITOR_STREET'),
        'postal_code' => env('BILLING_CREDITOR_POSTAL_CODE'),
        'city' => env('BILLING_CREDITOR_CITY'),
        'country' => env('BILLING_CREDITOR_COUNTRY', 'CH'),
        // IBAN or QR-IBAN (CH/LI), spaces are ignored.
        'iban' => env('BILLING_CREDITOR_IBAN'),
        // Optional, printed in the invoice header.
        'email' => env('BILLING_CREDITOR_EMAIL'),
        'website' => env('BILLING_CREDITOR_WEBSITE'),
        'vat_number' => env('BILLING_VAT_NUMBER'),
    ],

    // QRR (needs a QR-IBAN), SCOR (creditor reference with a normal IBAN) or NON; empty: QRR with a
    // QR-IBAN, otherwise SCOR.
    'reference_type' => env('BILLING_REFERENCE_TYPE'),

    // Optional customer identification of the bank (BESR-ID) at the start of QR references.
    'qr_customer_id' => env('BILLING_QR_CUSTOMER_ID'),

    // Currency of plans without one. The Swiss QR-bill only supports CHF and EUR; invoices in other
    // currencies have no payment part.
    'currency' => env('BILLING_CURRENCY', 'CHF'),

    // Optional line below the total, e.g. "Not subject to VAT." (no VAT is calculated).
    'vat_note' => env('BILLING_VAT_NOTE'),

    // Before the year in the invoice number, e.g. "R-" gives R-2026-0001.
    'number_prefix' => env('BILLING_NUMBER_PREFIX', ''),

    'payment_terms_days' => (int) env('BILLING_PAYMENT_TERMS_DAYS', 30),

    // One reminder mail to the user this many days after the due date; empty: no reminders.
    'reminder_days' => $reminderDays === null || $reminderDays === '' ? null : (int) $reminderDays,

    // Overdue invoices are reported to this address; empty: to all admins.
    'admin_email' => env('BILLING_ADMIN_EMAIL'),

];
