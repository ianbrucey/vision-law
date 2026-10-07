<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by AccessControl::authorize() on denial. The HTTP status encodes the
 * 03-contract.md leak rules: 404 when the actor has no grant at all (or is
 * cross-org, or the matter is missing — existence must not leak), 403 when
 * the matter is visible but the role is insufficient for the action.
 */
class AccessDeniedException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message = 'Access denied.'
    ) {
        parent::__construct($message);
    }
}
