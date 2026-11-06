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
 * Inside those parts, a few powers are not given to everyone who reaches them.
 * Those are the capabilities below, asked by the routes and the pages alike:
 *
 *   - Super Admin: all of them, including booking work for today - everybody
 *     else books from tomorrow.
 *   - Admin Officer: creates projects and manages a project's materials.
 *   - HR Admin: none. Runs the scheduling side, but creates no projects.
 *   - Purchasing Admin: manages a project's materials.
 *
 * Tasks and reports are otherwise the crew's: the office reads them. A lead's
 * own powers are decided per project, by ProjectPolicy and the rules services.
 */
class RoleAccess
{
    public const SCHEDULING = 'scheduling';

    public const INVENTORY = 'inventory';

    public const CREATE_PROJECTS = 'create-projects';

    public const MANAGE_TASKS = 'manage-tasks';

    public const FILE_REPORTS = 'file-reports';

    public const MANAGE_PROJECT_MATERIALS = 'manage-project-materials';

    public const OVERALL_PROGRESS = 'overall-progress';

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
     * @var array<string, array<int, string>>
     */
    private const CAPABILITIES = [
        User::ROLE_SUPER_ADMIN => [self::CREATE_PROJECTS, self::MANAGE_TASKS, self::FILE_REPORTS, self::MANAGE_PROJECT_MATERIALS, self::OVERALL_PROGRESS],
        User::ROLE_ADMIN_OFFICER => [self::CREATE_PROJECTS, self::MANAGE_PROJECT_MATERIALS, self::OVERALL_PROGRESS],
        User::ROLE_PURCHASING_ADMIN => [self::MANAGE_PROJECT_MATERIALS],
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

    public static function can(?User $user, string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES[$user?->role] ?? [], true);
    }

    /**
     * Every role that reaches this part of the portal - who a notice about
     * it is addressed to.
     *
     * @return array<int, string>
     */
    public static function rolesFor(string $area): array
    {
        return array_keys(array_filter(
            self::AREAS,
            fn (array $areas): bool => in_array($area, $areas, true)
        ));
    }
}
