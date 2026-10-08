<?php

namespace App\Services;

/**
 * Thrown by DocumentStore implementations for storage-level failures:
 * missing bytes, refcount-guarded deletes, failed moves.
 */
class DocumentStoreException extends \RuntimeException {}
