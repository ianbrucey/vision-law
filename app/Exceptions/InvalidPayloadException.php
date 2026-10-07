<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by AuditLogger when an audit payload contains privileged keys
 * (password/secret/token/hash/recovery) per the 03-contract.md blocklist.
 */
class InvalidPayloadException extends InvalidArgumentException
{
    //
}
