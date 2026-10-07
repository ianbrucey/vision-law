<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Matter;
use App\Models\User;
use App\Services\InvitationService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Admin invitation management (03-contract.md §Routes, admin section).
 * Backend only — no Blade per 001-D06; JSON responses.
 *
 * The token hash is NEVER serialized (leak sentinels, 00-brief.md).
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
    ) {}

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function invitationPayload(Invitation $invitation): array
    {
        /** @var CarbonInterface $expiresAt */
        $expiresAt = $invitation->expires_at;
        /** @var CarbonInterface|null $createdAt */
        $createdAt = $invitation->created_at;

        $status = 'pending';
        if ($invitation->accepted_at !== null) {
            $status = 'accepted';
        } elseif ($invitation->revoked_at !== null) {
            $status = 'revoked';
        } elseif ($expiresAt->isPast()) {
            $status = 'expired';
        }

        return [
            'id' => (string) $invitation->getKey(),
            'email' => $invitation->email,
            'role' => $invitation->role,
            'matter_id' => $invitation->matter_id === null ? null : (string) $invitation->matter_id,
            'status' => $status,
            'expires_at' => $expiresAt->toIso8601String(),
            'created_at' => $createdAt?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $invitations = Invitation::where('org_id', $this->admin($request)->org_id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $invitations->map(fn (Invitation $invitation) => $this->invitationPayload($invitation)),
        ]);
    }

    /**
     * Invite (email + role + optional matter scope). The plaintext token is
     * mailed to the invitee; only its hash is stored.
     */
    public function store(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        /** @var array{email: string, role: string, matter_id?: ?string} $validated */
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(InvitationService::validRoles())],
            'matter_id' => ['nullable', 'uuid'],
        ]);

        $matter = null;
        if (! empty($validated['matter_id'])) {
            // Cross-org matter → 404 (no existence leak).
            $matter = Matter::where('org_id', $admin->org_id)->findOrFail($validated['matter_id']);
        }

        $invitation = $this->invitations->invite(
            $admin->organization,
            $validated['email'],
            $validated['role'],
            $matter,
            $admin,
        );

        return response()->json(['data' => $this->invitationPayload($invitation)], 201);
    }

    /**
     * Revoke a pending invitation. Idempotent; accepted invitations cannot
     * be revoked.
     */
    public function destroy(Request $request, string $invitation): Response
    {
        $admin = $this->admin($request);

        $model = Invitation::where('org_id', $admin->org_id)->findOrFail($invitation);

        $this->invitations->revoke($model, $admin);

        return response()->noContent();
    }
}
