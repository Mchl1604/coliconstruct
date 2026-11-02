<?php

namespace App\Services;

use App\Support\LuzonLocations;
use App\Support\ProjectAddress;
use Illuminate\Support\Str;

/**
 * Reads an old free-text address into the parts ProjectAddress keeps.
 *
 * Only what the text really says is filled in, read from the end of the line:
 * province, then city or municipality, then barangay. Nothing is guessed. An
 * address that stops short keeps its text, and the Edit Details dialog asks for
 * the missing parts the next time it is saved.
 */
class ProjectAddressSplitter
{
    /**
     * Blank parts, for an address that could not be read at all.
     */
    private const NO_PARTS = [
        'address_province' => null,
        'address_municipality' => null,
        'address_barangay' => null,
        'address_street' => null,
        'address_house_no' => null,
    ];

    /**
     * The columns to save: the parts found, and `address` rewritten from them
     * only when the barangay was found too.
     *
     * @return array<string, string|null>
     */
    public function split(string $address): array
    {
        $segments = array_values(array_filter(array_map('trim', explode(',', $address)), 'strlen'));
        $province = $this->takeProvince($segments);

        if ($province === null) {
            return self::NO_PARTS + ['address' => $address];
        }

        $municipality = $this->take($segments, LuzonLocations::municipalities($province));
        $barangay = $municipality === null
            ? null
            : $this->take($segments, LuzonLocations::barangays($province, $municipality));

        $parts = ['address_province' => $province, 'address_municipality' => $municipality];

        if ($barangay === null) {
            return $parts + self::NO_PARTS + ['address' => $address];
        }

        [$houseNo, $street] = $this->houseAndStreet($segments);

        if (mb_strlen((string) $street) > 255 || mb_strlen((string) $houseNo) > 50) {
            return $parts + self::NO_PARTS + ['address' => $address];
        }

        $parts += [
            'address_barangay' => $barangay,
            'address_street' => $street,
            'address_house_no' => $houseNo,
        ];

        return $parts + ['address' => ProjectAddress::compose($parts)];
    }

    /**
     * The province the line ends with. A line may end on a Metro Manila city
     * instead, which is left in place for the next step to take.
     *
     * @param  array<int, string>  $segments
     */
    private function takeProvince(array &$segments): ?string
    {
        $last = $segments === [] ? '' : $segments[array_key_last($segments)];
        $plain = $this->plain($last);

        if (in_array($plain, ['metro manila', 'ncr', 'national capital region'], true)) {
            array_pop($segments);

            return 'Metro Manila';
        }

        foreach (LuzonLocations::provinces() as $province) {
            if ($this->plain($province) === $plain || ($province === 'Mountain Province' && $plain === 'mt province')) {
                array_pop($segments);

                return $province;
            }
        }

        return $this->match($last, LuzonLocations::municipalities('Metro Manila')) === null ? null : 'Metro Manila';
    }

    /**
     * Take the last segment if it names one of these places.
     *
     * @param  array<int, string>  $segments
     * @param  array<int, string>  $places
     */
    private function take(array &$segments, array $places): ?string
    {
        $place = $segments === [] ? null : $this->match($segments[array_key_last($segments)], $places);

        if ($place !== null) {
            array_pop($segments);
        }

        return $place;
    }

    /**
     * @param  array<int, string>  $places
     */
    private function match(string $text, array $places): ?string
    {
        $wanted = $this->placeKey($text);

        foreach ($places as $place) {
            if ($this->placeKey($place) === $wanted) {
                return $place;
            }
        }

        return null;
    }

    /**
     * What is left of the line: a leading lot, block, unit or building, or a
     * bare house number, and then the street.
     *
     * @param  array<int, string>  $segments
     * @return array{0: string|null, 1: string|null}
     */
    private function houseAndStreet(array $segments): array
    {
        $first = $segments[0] ?? '';

        if (count($segments) > 1 && preg_match('/^(lot|blk|block|unit|bldg|building|house|phase)\b/i', $first)) {
            return [$first, implode(', ', array_slice($segments, 1))];
        }

        if (preg_match('/^(\d+[A-Za-z-]*)\s+(.+)$/', $first, $found)) {
            $segments[0] = $found[2];

            return [$found[1], implode(', ', $segments)];
        }

        return [null, $segments === [] ? null : implode(', ', $segments)];
    }

    /**
     * "Tagaytay City", "City of Tagaytay" and "tagaytay" are one place, and
     * "Brgy. San Antonio" is "San Antonio (Pob.)".
     */
    private function placeKey(string $name): string
    {
        $name = Str::ascii(Str::lower($name));
        $name = (string) preg_replace('/\(.*?\)/', ' ', $name);
        $name = (string) preg_replace('/^\s*(city of|municipality of|brgy\.?|bgy\.?|barangay)\s+/', '', $name);
        $name = (string) preg_replace('/\s+city\s*$/', '', $name);

        return $this->plain($name);
    }

    /**
     * Lower case, unaccented, and nothing but letters, digits and single spaces.
     */
    private function plain(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(Str::lower($name))));
    }
}
