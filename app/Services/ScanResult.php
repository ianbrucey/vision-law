<?php

namespace App\Services;

/**
 * Immutable malware-scan outcome (007, C-03). `signature` is the
 * engine-reported threat name (e.g. `Eicar-Test-Signature`) when infected.
 */
final class ScanResult
{
    public function __construct(
        public readonly ScanStatus $status,
        public readonly ?string $signature = null,
    ) {}
}
