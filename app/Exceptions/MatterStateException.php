<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain failure in the matter lifecycle (spec 006 T-02).
 *
 * Carries a 03-contract.md error-catalog entry: the HTTP status, the
 * contract `code` string, and any extra body keys (e.g.
 * `legal_next_states`). Rendered to JSON by bootstrap/app.php.
 *
 * $denialAuditEvent is NEVER rendered — when set, the throwing service
 * writes that audit event before the exception leaves the service layer,
 * so the denial is recorded even though the mutation transaction rolls
 * back.
 */
class MatterStateException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  extra JSON body keys (never privileged data)
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly array $context = [],
        public readonly ?string $denialAuditEvent = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    /**
     * JSON body for the contract error catalog: {code, ...context}.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return array_merge(['code' => $this->errorCode], $this->context);
    }
}
