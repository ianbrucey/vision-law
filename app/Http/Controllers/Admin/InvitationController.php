<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Matter;
use App\Models\User;
use App\Services\InvitationService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin invitation management (03-contract.md §Routes, admin section).
 *
 * Dual response (spec 004 004-D01): web requests (Accept: text/html) receive
 * Blade views / redirects; API clients ($request->wantsJson()) receive the
 * existing JSON shapes unchanged.
 *
 * The token hash is NEVER serialized (leak sentinels, 00-brief.md). The
 * plaintext token is flashed to the session exactly once on web create —
 * shown once, never logged, never persisted.
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

    public function index(Request $request): JsonResponse|View
    {
        $invitations = Invitation::where('org_id', $this->admin($request)->org_id)
            ->orderByDesc('created_at')
            ->get();

        $payloads = $invitations->map(fn (Invitation $invitation) => $this->invitationPayload($invitation));

        if ($request->wantsJson()) {
            return response()->json(['data' => $payloads]);
        }

        // The payload carries no token_hash (leak sentinels) — safe for Blade.
        return view('admin.invitations.index', ['invitations' => $payloads]);
    }

    /**
     * Invite (email + role + optional matter scope). The plaintext token is
     * mailed to the invitee; only its hash is stored. On web the one-time
     * accept link is flashed to the session (004-D01).
     */
    public function store(Request $request): JsonResponse|RedirectResponse
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

        $issued = $this->invitations->inviteWithToken(
            $admin->organization,
            $validated['email'],
            $validated['role'],
            $matter,
            $admin,
        );

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->invitationPayload($issued->invitation)], 201);
        }

        // 004-D01: the one-time accept link — flashed to the session, shown
        // once on the index page, never logged, never persisted.
        return redirect()
            ->route('admin.invitations.index')
            ->with('invitation_accept_url', route('invitations.show', ['token' => $issued->token]));
    }

    /**
     * Revoke a pending invitation. Idempotent; accepted invitations cannot
     * be revoked.
     */
    public function destroy(Request $request, string $invitation): Response|RedirectResponse
    {
        $admin = $this->admin($request);

        $model = Invitation::where('org_id', $admin->org_id)->findOrFail($invitation);

        $this->invitations->revoke($model, $admin);

        if ($request->wantsJson()) {
            return response()->noContent();
        }

        // 03-contract.md C-03: toast confirming the revocation.
        return redirect()->back()->with('toast', [
            'message' => "Invitation for {$model->email} revoked.",
            'tone' => 'ok',
        ]);
    }
}
