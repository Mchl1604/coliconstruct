<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The Inventory side of the office portal.
 *
 * Reached by Super Admin, Admin Officer and Purchasing Admin - the
 * `area:inventory` middleware on the route group is the whole of that rule,
 * see RoleAccess. The page itself is a shell: the material list on it loads a
 * page at a time from MaterialController.
 */
class InventoryController extends Controller
{
    public function index(): View
    {
        return view('super-admin.inventory');
    }
}
