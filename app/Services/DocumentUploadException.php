<?php

namespace App\Services;

/**
 * Upload-pipeline rejection (007, T-02). Carries the contract error code
 * (03-contract.md §Error catalog) plus the HTTP status the controller must
 * render — controllers translate these, never invent codes.
 */
class DocumentUploadException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
