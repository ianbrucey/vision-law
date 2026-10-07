<?php

namespace App\Actions\Fortify;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Self-registration (C-01, C-02; 001-D09).
 *
 * The org and role resolve from a valid invitation token when one is
 * supplied, otherwise from the oldest organization whose settings allow open
 * registration ("registration_mode: open"). Open registrations get the least
 * privileged role (viewer). Duplicate emails are rejected with a generic
 * message (no enumeration). User-create + role-assign + audit commit
 * atomically per the contract's transaction boundary.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        /** @var array{name: string, email: string, password: string, invitation_token?: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
            'invitation_token' => ['nullable', 'string'],
        ])->validate();

        [$organization, $role, $invitation] = $this->resolveOrgAndRole($validated);

        $email = $validated['email'];

        // Unique per org — but the message never reveals the duplication.
        if (User::where('org_id', $organization->getKey())->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => [__('If this email is available, a verification link was sent.')],
            ]);
        }

        return DB::transaction(function () use ($validated, $email, $organization, $role, $invitation): User {
            $user = User::create([
                'org_id' => $organization->getKey(),
                'name' => $validated['name'],
                'email' => $email,
                // The 'hashed' cast hashes this on set.
                'password' => $validated['password'],
            ]);

            $roleModel = Role::where('org_id', $organization->getKey())
                ->where('name', $role)
                ->where('guard_name', (string) config('auth.defaults.guard', 'web'))
                ->firstOrFail();

            $user->assignRole($roleModel);

            if ($invitation instanceof Invitation) {
                $invitation->forceFill(['accepted_at' => now()])->save();

                AuditLogger::log('invitation.accepted', $user, [
                    'org_id' => (string) $organization->getKey(),
                    'email' => $user->email,
                    'role' => $role,
                    'invited_by' => (string) $invitation->invited_by,
                ]);
            }

            AuditLogger::log('user.created', $user, [
                'actor_id' => (string) $user->getKey(),
                'org_id' => (string) $organization->getKey(),
                'role' => $role,
            ]);

            return $user;
        });
    }

    /**
     * @param  array{name: string, email: string, password: string, invitation_token?: string}  $validated
     * @return array{Organization, string, ?Invitation}
     */
    private function resolveOrgAndRole(array $validated): array
    {
        if (! empty($validated['invitation_token'])) {
            return $this->resolveFromInvitation($validated['invitation_token'], $validated['email']);
        }

        $organization = Organization::query()
            ->where('settings->registration_mode', 'open')
            ->orderBy('created_at')
            ->first();

        if (! $organization instanceof Organization) {
            throw new HttpResponseException(
                response()->json([
                    'code' => 'registration_unavailable',
                    'message' => 'Registration is not currently available.',
                ], 422)
            );
        }

        return [$organization, 'viewer', null];
    }

    /**
     * @return array{Organization, string, Invitation}
     */
    private function resolveFromInvitation(string $token, string $email): array
    {
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->first();

        if (! $invitation instanceof Invitation) {
            $this->throwInvalidInvitation();
        }

        /** @var CarbonInterface $expiresAt */
        $expiresAt = $invitation->expires_at;

        $valid = $invitation->revoked_at === null
            && $invitation->accepted_at === null
            && ! $expiresAt->isPast()
            && strcasecmp((string) $invitation->email, $email) === 0;

        if (! $valid) {
            $this->throwInvalidInvitation();
        }

        return [$invitation->organization, (string) $invitation->role, $invitation];
    }

    /**
     * Generic rejection: never reveal whether the token exists, expired, or
     * is bound to a different email.
     *
     * @throws ValidationException always
     */
    private function throwInvalidInvitation(): never
    {
        throw ValidationException::withMessages([
            'invitation_token' => [__('This invitation is invalid or has expired.')],
        ]);
    }
}
