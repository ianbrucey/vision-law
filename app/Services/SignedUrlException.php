<?php

namespace App\Services;

/**
 * Signed preview/download URL verification failure (spec 007 T-03).
 * The error code is safe to expose — it never carries document data.
 */
class SignedUrlException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
    ) {
        parent::__construct("Signed URL verification failed: {$errorCode}");
    }
}
