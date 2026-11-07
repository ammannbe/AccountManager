<?php

namespace App\Enums;

/**
 * Payment reference of the Swiss QR-bill: QR reference (only with a QR-IBAN), creditor reference
 * (ISO 11649, only with a normal IBAN) or none.
 */
enum ReferenceType: string
{
    case Qrr = 'QRR';
    case Scor = 'SCOR';
    case Non = 'NON';
}
