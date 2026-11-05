<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spec 012 chat slice: the loop-proof tool endpoint.
 *
 * Brain-API-style internal endpoint the AGENT SERVICE calls (never the
 * browser) to implement the read-only list_my_matters tool. Gated by
 * the shared internal token (constant-time compare); the user_id is
 * the Laravel user the turn belongs to, and matters are scoped by the
 * same Matter::accessibleBy rule as every other surface — the tool
 * cannot see anything the user cannot.
 */
class MattersToolController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $expected = config('chat.internal_token');
        $provided = $request->bearerToken() ?? $request->header('X-Internal-Token');

        if (! is_string($expected) || $expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['code' => 'forbidden'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        $user = User::query()->find($validated['user_id']);
        if (! $user instanceof User) {
            return response()->json(['code' => 'not_found'], 404);
        }

        $matters = Matter::query()
            ->accessibleBy($user)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'matter_number', 'title', 'lifecycle_state', 'matter_type'])
            ->map(fn (Matter $m): array => [
                'id' => $m->id,
                'number' => $m->matter_number,
                'title' => $m->title,
                'status' => $m->lifecycle_state,
                'type' => $m->matter_type,
            ])
            ->values();

        return response()->json(['matters' => $matters]);
    }
}
