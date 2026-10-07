<?php

namespace App\Exceptions;

/**
 * The k-anonymity breached-password API could not be reached (001-D07).
 * Callers must fail closed: reject the password attempt with a retryable
 * error, never let it through.
 */
class BreachedPasswordCheckUnavailableException extends \RuntimeException {}
