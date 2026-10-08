<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// C-05: nightly purge of expired, unaccepted invitations.
Schedule::command('invitations:purge-expired')->daily();

// 007 T-02: expire idle chunked-upload sessions (24h) and retry stalled
// malware scans / raise the ops alert (C-02/C-03).
Schedule::command('documents:sweep-stale-scans')->everyFiveMinutes();

// 007 T-09: nightly retention evaluation — flag documents at
// retention thresholds and auto-queue destroy/archive dispositions.
Schedule::command('retention:evaluate')->daily();
