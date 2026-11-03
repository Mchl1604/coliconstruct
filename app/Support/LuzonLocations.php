<?php

namespace App\Support;

/**
 * The provinces, cities, municipalities and barangays of Luzon.
 *
 * Read from resources/data/luzon-locations.json, built from the Philippine
 * Standard Geographic Code as published at psgc.gitlab.io/api. Luzon there
 * means its island group: Regions I to V, MIMAROPA, CAR and NCR. NCR has no
 * province of its own, so its cities are filed under "Metro Manila".
 */
class LuzonLocations
{
    /**
     * @var array<string, array<string, array<int, string>>>|null
     */
    private static ?array $locations = null;

    /**
     * The whole list: province => city or municipality => barangays.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    public static function all(): array
    {
        return self::$locations ??= json_decode(
            (string) file_get_contents(resource_path('data/luzon-locations.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return array<int, string>
     */
    public static function provinces(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array<int, string>
     */
    public static function municipalities(?string $province): array
    {
        return array_keys(self::all()[$province ?? ''] ?? []);
    }

    /**
     * @return array<int, string>
     */
    public static function barangays(?string $province, ?string $municipality): array
    {
        return self::all()[$province ?? ''][$municipality ?? ''] ?? [];
    }
}
