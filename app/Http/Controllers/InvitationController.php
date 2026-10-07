<?php

namespace App\Http\Controllers;

use App\Exceptions\InvitationInvalidException;
use App\Models\User;
use App\Services\InvitationService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Invitation landing + accept (03-contract.md §Routes).
 *
 * Dual response (spec 004 004-D01): web requests (Accept: text/html) receive
 * Blade views / redirects; API clients ($request->wantsJson()) receive the
 * existing JSON shapes unchanged.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
    ) {}

    /**
     * Guest: inspect a valid invitation token. Invalid/expired/revoked/
     * accepted tokens → identical 404 in all four states (no enumeration).
     */
    public function show(string $token, Request $request): JsonResponse|View
    {
        $invitation = $this->invitations->findValid($token);

        if ($invitation === null) {
            if ($request->wantsJson()) {
                return response()->json(['code' => 'not_found'], 404);
            }

            // Identical generic 404 page in every failure state — the token
            // is the credential, and nothing about it is revealed.
            abort(404);
        }

        /** @var CarbonInterface $expiresAt */
        $expiresAt = $invitation->expires_at;

        if ($request->wantsJson()) {
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

        // NOTE (T-01): the plaintext token is deliberately NOT passed to the
        // view — the architecture leak sentinel asserts it never appears in a
        // response body. Ticket 2/3 (real accept page) must resolve how the
        // register form and sign-in next-link carry the token without
        // tripping that door.
        return view('invitations.show', [
            'email' => $invitation->email,
            'role' => $invitation->role,
            'organizationName' => (string) $invitation->organization->name,
            'matterName' => $invitation->matter?->title,
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Auth: accept the invitation as the signed-in user. The token is bound
     * to the invitee email — a different signed-in user is rejected with the
     * generic 422 {code: "invitation_invalid"} (C-05, no enumeration); on
     * web that renders as a page-level banner on the accept page.
     */
    public function accept(Request $request, string $token): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        if ($request->wantsJson()) {
            $accepted = $this->invitations->accept($token, $user);

            return response()->json([
                'data' => [
                    'user_id' => (string) $accepted->getKey(),
                    'org_id' => (string) $accepted->org_id,
                    'email' => $accepted->email,
                ],
            ]);
        }

        try {
            $this->invitations->accept($token, $user);
        } catch (InvitationInvalidException) {
            // Generic invitation_invalid as a page-level banner on the accept
            // page — no detail about which check failed (no enumeration).
            return redirect()
                ->route('invitations.show', ['token' => $token])
                ->with('invitation_error', 'invitation_invalid');
        }

        return redirect('/')->with('toast', [
            'message' => 'Welcome — your invitation has been accepted.',
            'tone' => 'ok',
        ]);
    }
}
