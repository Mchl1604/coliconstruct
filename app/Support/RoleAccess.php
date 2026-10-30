<?php

namespace App\Support;

use App\Models\User;

/**
 * Which parts of the office portal each office role reaches.
 *
 * One place decides this, so the route middleware, the sidebar and the pages
 * that draw a link to another part all give the same answer. Every office
 * role shares one portal - the same shell, the same header - and what differs
 * is which of its parts they may open:
 *
 *   - Scheduling: the dashboard, projects, schedules, tasks, technicians,
 *     reports and Configuration.
 *   - Inventory: the material list, and the stock kept against it.
 *
 * What each role may do inside a part it reaches is still decided where it
 * always was - a Super Admin archives, an HR Admin does not - so this is the
 * door, not the whole of the permission model.
 */
class RoleAccess
{
    public const SCHEDULING = 'scheduling';

    public const INVENTORY = 'inventory';

    /**
     * @var array<string, array<int, string>>
     */
    private const AREAS = [
        User::ROLE_SUPER_ADMIN => [self::SCHEDULING, self::INVENTORY],
        User::ROLE_ADMIN => [self::SCHEDULING],
        User::ROLE_ADMIN_OFFICER => [self::SCHEDULING, self::INVENTORY],
        User::ROLE_PURCHASING_ADMIN => [self::INVENTORY],
    ];

    /**
     * The parts of the portal this account reaches; none for a technician or
     * a client, whose portals are their own.
     *
     * @return array<int, string>
     */
    public static function areasFor(?User $user): array
    {
        return self::AREAS[$user?->role] ?? [];
    }

    public static function allows(?User $user, string $area): bool
    {
        return in_array($area, self::areasFor($user), true);
    }
}
