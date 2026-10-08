<?php

namespace App\Services;

/**
 * Outcome of a ClamAV malware scan (007, C-03).
 *
 * UNAVAILABLE is never treated as clean: the upload stays `scanning` and
 * the stale-scan sweeper raises the ops alert after the configured
 * threshold (config `document.clamav.alert_after_minutes`).
 */
enum ScanStatus: string
{
    case CLEAN = 'clean';
    case INFECTED = 'infected';
    case UNAVAILABLE = 'unavailable';
}
