<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Session management (C-07; 03-contract.md §Routes).
 * Backend only — no Blade per 001-D06; JSON responses.
 *
 * Reads the database session rows (production uses the database driver).
 * Destructive actions sit behind `password.confirm`.
 */
class SessionController extends Controller
{
    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        return $user;
    }

    /**
     * User-visible session list; the current session is flagged.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get();

        return response()->json([
            'data' => $sessions->map(fn ($session) => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_activity' => (int) $session->last_activity,
                'is_current' => $session->id === $currentId,
            ])->values()->all(),
        ]);
    }

    /**
     * Per-session revoke. Revoking the current session also logs out.
     */
    public function destroy(Request $request, string $id): Response|JsonResponse
    {
        $user = $this->user($request);

        $exists = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', $id)
            ->exists();

        if (! $exists) {
            return response()->json(['code' => 'not_found'], 404);
        }

        DB::transaction(function () use ($request, $user, $id): void {
            DB::table('sessions')->where('id', $id)->delete();

            AuditLogger::log('session.revoked', $user, [
                'actor_id' => (string) $user->getKey(),
                'revoked_count' => 1,
            ]);

            if ($id === $request->session()->getId()) {
                Auth::guard()->logout();
            }
        });

        return response()->noContent();
    }

    /**
     * Revoke ALL sessions (including the current one) and log out.
     */
    public function destroyAll(Request $request): Response
    {
        $user = $this->user($request);

        DB::transaction(function () use ($user): void {
            $revoked = DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->delete();

            AuditLogger::log('session.revoked_all', $user, [
                'actor_id' => (string) $user->getKey(),
                'revoked_count' => $revoked,
            ]);

            Auth::guard()->logout();
        });

        return response()->noContent();
    }
}
