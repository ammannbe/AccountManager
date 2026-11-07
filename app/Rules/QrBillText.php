<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Sprain\SwissQrBill\QrCode\QrCode;

/**
 * Only characters the Swiss QR-bill supports (Latin, Latin-1 Supplement, Latin Extended-A, a few
 * more and €); others would silently disappear from the payment part.
 */
class QrBillText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/[^'.QrCode::SUPPORTED_CHARACTERS.']/u', $value) === 1) {
            $fail(__('The :attribute contains characters that cannot be printed on the payment slip.'));
        }
    }
}
