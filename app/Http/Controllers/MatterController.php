<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal matter read — the authorization proving ground (001-D03). Full
 * matter CRUD is a later feature; this controller exists so the verdicts can
 * exercise real authorization against a real securable object.
 *
 * RequireMatterAccess:view runs BEFORE any data access; the authorized
 * matter rides on the request attributes. The 404 branch below is
 * unreachable through the route table (the middleware always sets it) and
 * exists only so the controller never trusts ambient state.
 */
class MatterController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $matter = $request->attributes->get('matter');

        if (! $matter instanceof Matter) {
            return response()->json(['code' => 'not_found'], 404);
        }

        return response()->json([
            'id' => (string) $matter->getKey(),
            'matter_number' => $matter->matter_number,
            'title' => $matter->title,
            'status' => $matter->status,
        ]);
    }
}
