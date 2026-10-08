<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain failure in document filing (spec 007 T-05).
 *
 * Carries a 03-contract.md error-catalog entry: the HTTP status, the
 * contract `code` string, and any extra body keys. Rendered to JSON by
 * bootstrap/app.php (same pattern as 006's MatterStateException).
 *
 * $denialAuditEvent is NEVER rendered — when set, the throwing service
 * writes that audit event BEFORE the exception leaves the service layer,
 * so the denial is recorded even though the mutation transaction rolls
 * back. Payloads carry IDs only, never titles (leak sentinels).
 */
class DocumentFilingException extends RuntimeException
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
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
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
