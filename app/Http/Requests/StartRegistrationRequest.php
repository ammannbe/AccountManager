<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;

class StartRegistrationRequest extends FormRequest
{
    /**
     * Humans need longer than this to fill in the form; bots submit right away.
     */
    public const MIN_SECONDS_TO_SUBMIT = 3;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'rendered_at' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->looksLikeABot()) {
                $validator->errors()->add('email', __('Registration failed. Please try again.'));
            }
        });
    }

    private function looksLikeABot(): bool
    {
        // Honeypot: hidden from humans and assistive technology.
        if ($this->filled('website')) {
            return true;
        }

        try {
            $renderedAt = (int) Crypt::decryptString((string) $this->input('rendered_at'));
        } catch (DecryptException) {
            return true;
        }

        return now()->getTimestamp() - $renderedAt < self::MIN_SECONDS_TO_SUBMIT;
    }
}
