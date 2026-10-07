<?php

namespace App\Services;

use App\Exceptions\InvitationInvalidException;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Matter;
use App\Models\MatterGrant;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionMatrixSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitation lifecycle (C-05; 03-contract.md §Domain service methods).
 *
 * - invite(): creates a 7-day single-use invitation; only the SHA-256 hash
 *   of the token is stored, the plaintext token travels by mail only.
 * - accept(): email-bound — the signed-in user's email must match the
 *   invitee email, otherwise a generic InvitationInvalidException (no
 *   enumeration of which check failed).
 * - revoke(): marks revoked; idempotent.
 * - purgeExpired(): deletes expired, unaccepted invitations (nightly command).
 */
class InvitationService
{
    /**
     * The five fixed org roles (03-contract.md permission matrix — the
     * matrix seeder is the single source of truth).
     *
     * @return list<string>
     */
    public static function validRoles(): array
    {
        return array_keys(PermissionMatrixSeeder::MATRIX);
    }

    /**
     * @throws ValidationException on bad role/email/matter/org
     */
    public function invite(
        Organization $org,
        string $email,
        string $role,
        ?Matter $matter,
        User $invitedBy
    ): Invitation {
        // 001-D13: the system org must never accept user assignment —
        // invitations mint users, so it can never be their target.
        if ((string) $org->getKey() === Organization::SYSTEM_ID) {
            throw ValidationException::withMessages([
                'org_id' => ['Invitations cannot target the system organization.'],
            ]);
        }

        $email = mb_strtolower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw ValidationException::withMessages([
                'email' => ['The email address is invalid.'],
            ]);
        }

        if (! in_array($role, self::validRoles(), true)) {
            throw ValidationException::withMessages([
                'role' => ['The role is invalid.'],
            ]);
        }

        if ($matter instanceof Matter && (string) $matter->org_id !== (string) $org->getKey()) {
            throw ValidationException::withMessages([
                'matter_id' => ['The matter does not belong to this organization.'],
            ]);
        }

        return DB::transaction(function () use ($org, $email, $role, $matter, $invitedBy): Invitation {
            $token = $this->generateUniqueToken();

            $invitation = Invitation::create([
                'org_id' => $org->getKey(),
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'role' => $role,
                'matter_id' => $matter?->getKey(),
                'invited_by' => $invitedBy->getKey(),
                'expires_at' => now()->addDays((int) config('visionlaw.invitation_ttl_days', 7)),
            ]);

            AuditLogger::log('invitation.created', $invitedBy, [
                'org_id' => (string) $org->getKey(),
                'email' => $email,
                'role' => $role,
                'invited_by' => (string) $invitedBy->getKey(),
            ]);

            // Queued per 03-contract.md (sync driver in tests).
            Mail::to($email)->queue(new InvitationMail(
                inviteeEmail: $email,
                orgName: (string) $org->name,
                role: $role,
                acceptUrl: url("/invitations/{$token}"),
                expiresAt: $this->expiresAtString($invitation),
            ));

            return $invitation;
        });
    }

    /**
     * Accept an invitation as the signed-in user.
     *
     * New users register through POST /register with the token (T-04); this
     * path is for an already-signed-in user whose email matches the invitee
     * email — e.g. grant-add for an existing org member. Every failure mode
     * (unknown/expired/revoked/accepted token, email mismatch, deactivated
     * account, cross-org account) denies generically and is audited.
     *
     * @throws InvitationInvalidException
     */
    public function accept(string $token, User $user): User
    {
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->first();
        $this->ensureAcceptable($invitation, $user);

        /** @var Invitation $invitation */
        return DB::transaction(function () use ($invitation, $user): User {
            $this->grantMatterScope($invitation, $user);

            $invitation->forceFill(['accepted_at' => now()])->save();

            AuditLogger::log('invitation.accepted', $user, [
                'org_id' => (string) $invitation->org_id,
                'email' => (string) $invitation->email,
                'role' => (string) $invitation->role,
                'invited_by' => (string) $invitation->invited_by,
            ]);

            return $user->fresh();
        });
    }

    /**
     * @throws ValidationException when the invitation was already accepted
     */
    public function revoke(Invitation $invitation, User $revokedBy): void
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => ['An accepted invitation cannot be revoked.'],
            ]);
        }

        // Idempotent: revoking twice is a no-op.
        if ($invitation->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($invitation, $revokedBy): void {
            $invitation->forceFill(['revoked_at' => now()])->save();

            AuditLogger::log('invitation.revoked', $revokedBy, [
                'org_id' => (string) $invitation->org_id,
                'email' => (string) $invitation->email,
                'role' => (string) $invitation->role,
                'invited_by' => (string) $invitation->invited_by,
            ]);
        });
    }

    /**
     * Delete expired, unaccepted invitations. Accepted invitations are kept
     * as the record of how the user joined.
     */
    public function purgeExpired(): int
    {
        return Invitation::whereNull('accepted_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    /**
     * Find a usable invitation by plaintext token, or null.
     */
    public function findValid(string $token): ?Invitation
    {
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->first();

        return $this->isUsable($invitation) ? $invitation : null;
    }

    /**
     * All checks run BEFORE any transaction opens: the denial audit must
     * survive the rollback that follows the throw.
     *
     * @throws InvitationInvalidException
     */
    private function ensureAcceptable(?Invitation $invitation, User $user): void
    {
        $denied = ! $this->isUsable($invitation)
            || $user->deactivated_at !== null
            || ($invitation instanceof Invitation
                && strcasecmp((string) $invitation->email, (string) $user->email) !== 0)
            || ($invitation instanceof Invitation
                && (string) $invitation->org_id !== (string) $user->org_id);

        if ($denied) {
            // Generic denial: never reveal whether the token exists, expired,
            // or is bound to a different email (no enumeration).
            AuditLogger::log(
                'invitation.accept.denied',
                $user,
                $invitation instanceof Invitation
                    ? [
                        'org_id' => (string) $invitation->org_id,
                        'email' => (string) $invitation->email,
                        'attempted_by' => (string) $user->getKey(),
                    ]
                    : ['attempted_by' => (string) $user->getKey()],
                explicitOrgId: $invitation instanceof Invitation
                    ? (string) $invitation->org_id
                    : (string) $user->org_id,
            );

            throw new InvitationInvalidException($invitation);
        }
    }

    private function isUsable(?Invitation $invitation): bool
    {
        if (! $invitation instanceof Invitation) {
            return false;
        }

        /** @var CarbonInterface $expiresAt */
        $expiresAt = $invitation->expires_at;

        return $invitation->revoked_at === null
            && $invitation->accepted_at === null
            && ! $expiresAt->isPast();
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (Invitation::where('token_hash', hash('sha256', $token))->exists());

        return $token;
    }

    /**
     * @return string the invitation expiry as a plain datetime string
     */
    private function expiresAtString(Invitation $invitation): string
    {
        /** @var CarbonInterface $expiresAt */
        $expiresAt = $invitation->expires_at;

        return $expiresAt->toDateTimeString();
    }

    /**
     * Invitation roles are org roles; matter grants need matter roles. Only
     * "viewer" exists in both enums — anything else degrades to viewer rather
     * than inventing a mapping the contract never defined.
     */
    private function matterRoleFor(string $orgRole): string
    {
        return in_array($orgRole, ['matter_owner', 'matter_admin', 'editor', 'viewer'], true)
            ? $orgRole
            : 'viewer';
    }

    /**
     * Create the matter-scoped grant for an invitation acceptance (row
     * creation only — evaluation of grants is T-06's AccessControl).
     * Idempotent: safe to call for both new-user registration (CreateNewUser,
     * T-04) and existing-user accept() (this service).
     */
    public function grantMatterScope(Invitation $invitation, User $user): void
    {
        if ($invitation->matter_id === null) {
            return;
        }

        MatterGrant::firstOrCreate(
            [
                'matter_id' => $invitation->matter_id,
                'user_id' => $user->getKey(),
            ],
            [
                'org_id' => $invitation->org_id,
                'role' => $this->matterRoleFor((string) $invitation->role),
                'granted_by' => $invitation->invited_by,
            ]
        );

        AuditLogger::log('matter.grant.created', $user, [
            'actor_id' => (string) $user->getKey(),
            'matter_id' => (string) $invitation->matter_id,
            'subject' => ['type' => 'user', 'id' => (string) $user->getKey()],
            'role' => $this->matterRoleFor((string) $invitation->role),
            'granted_by' => (string) $invitation->invited_by,
        ]);
    }
}
