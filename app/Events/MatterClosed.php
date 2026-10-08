<?php

namespace App\Events;

use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by MatterService::closeMatter() after the CLOSED transition
 * commits (spec 006 T-02; 03-contract.md §Domain service methods).
 *
 * Phase 5 hook: NO listener is registered in 006 (retention-hold
 * automation, notification delivery, and disposition all live in later
 * phases). The event exists so those phases can subscribe without
 * touching the lifecycle code.
 */
class MatterClosed
{
    use Dispatchable;

    public function __construct(
        public readonly Matter $matter,
        public readonly ?string $note,
        public readonly User $actor,
    ) {}
}
