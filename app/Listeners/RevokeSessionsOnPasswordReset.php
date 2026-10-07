<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;

/**
 * C-04: a completed password reset revokes ALL of the user's sessions
 * (database session rows keyed by user_id; the remember-me token was already
 * rotated by Fortify's CompletePasswordReset) and audits the revocation.
 */
class RevokeSessionsOnPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $revoked = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->delete();

        AuditLogger::log('auth.password.reset', $user, [
            'actor_id' => (string) $user->getKey(),
            'revoked_count' => $revoked,
        ]);
    }
}
