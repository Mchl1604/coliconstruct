<?php

namespace App\Http\Controllers;

use App\Support\LuzonLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The next list down for the project address fields - see addressFields.js.
 */
class ProjectAddressController extends Controller
{
    /**
     * A province's municipalities or, with a municipality too, its barangays.
     * Anything outside the list answers with an empty one.
     */
    public function options(Request $request): JsonResponse
    {
        $province = $request->string('province')->toString();
        $municipality = $request->string('municipality')->toString();

        return response()->json([
            'options' => $municipality === ''
                ? LuzonLocations::municipalities($province)
                : LuzonLocations::barangays($province, $municipality),
        ]);
    }
}
