<?php

namespace App\Support;

use App\Models\User;

/**
 * Where each role lands after signing in.
 *
 * One place decides this, so the login redirect, the "already signed in"
 * bounce and the role middleware's refusal all send a user to exactly the
 * same portal instead of arguing with each other.
 */
class PortalHome
{
    /**
     * The route each role calls home. Admin shares the super admin's portal:
     * same sidebar, same pages, same routes.
     *
     * @var array<string, string>
     */
    private const ROUTES = [
        'super_admin' => 'super-admin.dashboard',
        'admin' => 'super-admin.dashboard',
        'lead_technician' => 'technician.schedule',
        'technician' => 'technician.schedule',
        'client' => 'client.dashboard',
    ];

    public static function routeName(?User $user): string
    {
        return self::ROUTES[$user?->role] ?? 'auth.login';
    }

    public static function url(?User $user): string
    {
        return route(self::routeName($user));
    }
}
