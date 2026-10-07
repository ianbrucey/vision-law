<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InvitationService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invitation landing + accept (03-contract.md §Routes).
 * Backend only — no Blade per 001-D06; JSON responses.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
    ) {}

    /**
     * Guest: inspect a valid invitation token. Invalid/expired/revoked/
     * accepted tokens → 404 (no enumeration of which check failed).
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->findValid($token);

        if ($invitation === null) {
            return response()->json(['code' => 'not_found'], 404);
        }

        /** @var CarbonInterface $expiresAt */
        $expiresAt = $invitation->expires_at;

        return response()->json([
            'data' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'organization' => [
                    'id' => (string) $invitation->org_id,
                    'name' => $invitation->organization->name,
                ],
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    /**
     * Auth: accept the invitation as the signed-in user. The token is bound
     * to the invitee email — a different signed-in user is rejected with the
     * generic 422 {code: "invitation_invalid"} (C-05, no enumeration).
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        $accepted = $this->invitations->accept($token, $user);

        return response()->json([
            'data' => [
                'user_id' => (string) $accepted->getKey(),
                'org_id' => (string) $accepted->org_id,
                'email' => $accepted->email,
            ],
        ]);
    }
}
