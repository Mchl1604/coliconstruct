<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * A project's address as the create wizard and the Edit Details dialog take it:
 * chosen from LuzonLocations, each list narrowed by the choice before it.
 */
class ProjectAddress
{
    /**
     * Each list is measured against what was submitted, so a municipality from
     * another province or a barangay from another municipality is refused.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $input): array
    {
        $province = self::text($input['address_province'] ?? null);
        $municipality = self::text($input['address_municipality'] ?? null);

        return [
            'address_province' => ['required', 'string', Rule::in(LuzonLocations::provinces())],
            'address_municipality' => ['required', 'string', Rule::in(LuzonLocations::municipalities($province))],
            'address_barangay' => ['required', 'string', Rule::in(LuzonLocations::barangays($province, $municipality))],
            'address_street' => ['required', 'string', 'max:255'],
            'address_house_no' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'address_province.required' => 'Choose a province.',
            'address_province.in' => 'Choose a province in Luzon.',
            'address_municipality.required' => 'Choose a city or municipality.',
            'address_municipality.in' => 'Choose a city or municipality in that province.',
            'address_barangay.required' => 'Choose a barangay.',
            'address_barangay.in' => 'Choose a barangay in that city or municipality.',
            'address_street.required' => 'Enter the street.',
        ];
    }

    /**
     * The columns to save from validated input, with the one-line `address`
     * every page, report and email prints.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    public static function columns(array $validated): array
    {
        $parts = [
            'address_province' => $validated['address_province'],
            'address_municipality' => $validated['address_municipality'],
            'address_barangay' => $validated['address_barangay'],
            'address_street' => $validated['address_street'] ?? null,
            'address_house_no' => $validated['address_house_no'] ?? null,
        ];

        return $parts + ['address' => self::compose($parts)];
    }

    /**
     * "12 Rizal St., Brgy. Canlalay, City of Biñan, Laguna". addressFields.js
     * writes the same line for the wizard's Review step.
     *
     * @param  array<string, string|null>  $parts
     */
    public static function compose(array $parts): string
    {
        $barangay = (string) ($parts['address_barangay'] ?? '');

        return collect([
            trim(($parts['address_house_no'] ?? '').' '.($parts['address_street'] ?? '')),
            str_starts_with($barangay, 'Barangay ') ? $barangay : 'Brgy. '.$barangay,
            $parts['address_municipality'] ?? '',
            $parts['address_province'] ?? '',
        ])->filter(fn (string $part): bool => $part !== '')->implode(', ');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
