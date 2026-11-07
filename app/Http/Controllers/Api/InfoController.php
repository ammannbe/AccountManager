<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated: lets the apps show the portal's name (docs/HOUSEHOLDS-API.md).
 * Never add secrets or user data here.
 */
class InfoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'households_url' => route('households.index'),
        ]);
    }
}
