<?php

namespace App\Billing;

use App\Rules\QrBillText;
use Illuminate\Validation\Rule;
use Symfony\Component\Intl\Countries;

/**
 * Billing address of a user as the Swiss QR-bill needs it (structured address): name or company,
 * street with house number (optional), postal code, city and country. Used by the portal profile
 * and the panel.
 */
class BillingAddress
{
    public const FIELDS = ['billing_name', 'billing_street', 'billing_postal_code', 'billing_city', 'billing_country'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'billing_name' => ['nullable', 'string', 'max:70', new QrBillText],
            'billing_street' => ['nullable', 'string', 'max:70', new QrBillText],
            'billing_postal_code' => ['nullable', 'required_with:billing_street,billing_city', 'string', 'max:16', new QrBillText],
            'billing_city' => ['nullable', 'required_with:billing_street,billing_postal_code', 'string', 'max:35', new QrBillText],
            'billing_country' => ['nullable', 'required_with:billing_postal_code,billing_city', 'string', Rule::in(Countries::getCountryCodes())],
        ];
    }

    /**
     * Field names in validation messages.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'billing_name' => __('Name or company'),
            'billing_street' => __('Street and number'),
            'billing_postal_code' => __('Postal code'),
            'billing_city' => __('City'),
            'billing_country' => __('Country'),
        ];
    }

    /**
     * Trims the values; without postal code and city there is no address (the country alone, e.g.
     * the preselected CH, is not kept).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public static function normalize(array $data): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $value = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $values[$field] = $value === '' ? null : $value;
        }

        if ($values['billing_country'] !== null) {
            $values['billing_country'] = strtoupper($values['billing_country']);
        }

        if ($values['billing_street'] === null && $values['billing_postal_code'] === null && $values['billing_city'] === null) {
            $values['billing_country'] = null;
        }

        return $values;
    }

    /**
     * Country names in the current language, Switzerland and Liechtenstein first.
     *
     * @return array<string, string>
     */
    public static function countries(): array
    {
        $names = Countries::getNames(app()->getLocale());

        return ['CH' => $names['CH'], 'LI' => $names['LI']] + $names;
    }
}
