<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProjectTypeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuration -> System Settings -> Project Types: the fixed list of work the
 * company does, read-only.
 *
 * Super Admin only, checked here rather than on the route group for the same
 * reason System Contents does it: the Configuration page is shared with Admin,
 * and this tab is not theirs.
 */
class ProjectTypeController extends Controller
{
    public function __construct(private readonly ProjectTypeCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === User::ROLE_SUPER_ADMIN, 403);

        return response()->json(['types' => $this->catalog->all()->all()]);
    }
}
