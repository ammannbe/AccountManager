<?php

namespace App\Http\Controllers\Api;

use App\Entitlements\Entitlements;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entitlements of a user for the calling service (see docs/ENTITLEMENTS-API.md).
 */
class EntitlementApiController extends Controller
{
    public function show(Request $request, string $sub, Entitlements $entitlements): JsonResponse
    {
        /** @var Service $service */
        $service = $request->attributes->get('service');
        $user = User::query()->where('oidc_sub', $sub)->first();
        $snapshot = $user ? $entitlements->snapshot($user, $service) : null;

        // Unknown users and users without an account at this service look the same.
        if ($snapshot === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['data' => $snapshot]);
    }
}
