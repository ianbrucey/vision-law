<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain failure in retention / legal holds / disposition (spec 007 T-09).
 *
 * Mirrors DocumentFilingException (T-05): carries a 03-contract.md
 * error-catalog entry — the HTTP status, the contract `code` string, and
 * any extra body keys — rendered to JSON by bootstrap/app.php.
 *
 * Payloads carry IDs only, never titles (leak sentinels, 00-brief.md).
 */
class RetentionException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  extra JSON body keys (never privileged data)
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly array $context = [],
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
