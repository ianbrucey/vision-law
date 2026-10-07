<?php

namespace App\Services;

use App\Models\Invitation;

/**
 * 004-D01 - an invitation plus its one-time plaintext token.
 *
 * Returned by InvitationService::inviteWithToken() so the admin controller
 * can display the accept link exactly once. The token is never logged, never
 * persisted beyond the hashed column, and never serialized - this DTO lives
 * only for the issuing request.
 */
final class InvitationWithToken
{
    public function __construct(
        public readonly Invitation $invitation,
        public readonly string $token,
    ) {}
}
