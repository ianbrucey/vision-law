<?php

namespace App\Exceptions;

use App\Models\Invitation;
use RuntimeException;

/**
 * Thrown when an invitation token is unknown, expired, revoked, already
 * accepted, or bound to a different email than the signed-in user
 * (03-contract.md: every case renders the same generic response — no
 * enumeration of which check failed).
 *
 * Rendered as 422 {code: "invitation_invalid"} by bootstrap/app.php.
 */
class InvitationInvalidException extends RuntimeException
{
    public function __construct(
        public readonly ?Invitation $invitation = null,
        string $message = 'This invitation is invalid or has expired.'
    ) {
        parent::__construct($message);
    }
}
