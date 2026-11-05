<?php

namespace App\Http\Controllers;

use App\Models\DetailForm;
use App\Models\User;
use App\Services\DetailFormCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuration -> System Settings -> Project Types -> Detail Forms: the fixed
 * forms project types ask for, listed read-only.
 *
 * Super Admin only, checked here for the same reason Project Types does it: the
 * Configuration page is shared with Admin, and this tab is not theirs.
 */
class DetailFormController extends Controller
{
    public function __construct(private readonly DetailFormCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['forms' => $this->catalog->all()->all()]);
    }

    public function show(Request $request, DetailForm $detailForm): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['form' => $this->catalog->forDisplay($detailForm)]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === User::ROLE_SUPER_ADMIN, 403);
    }
}
