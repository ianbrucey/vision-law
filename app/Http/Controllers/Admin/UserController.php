<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvitationService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin user management (03-contract.md §Routes, admin section).
 * Backend only — no Blade per 001-D06; JSON responses.
 *
 * Every query is scoped to the admin's org_id, so the system org (001-D13)
 * can never appear in listings and can never receive users.
 */
class UserController extends Controller
{
    use PasswordValidationRules;

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        return $user;
    }

    /**
     * @return Builder<User>
     */
    private function orgUsers(Request $request)
    {
        return User::where('org_id', $this->admin($request)->org_id);
    }

    private function roleModel(string $orgId, string $role): Role
    {
        return Role::where('org_id', $orgId)
            ->where('name', $role)
            ->where('guard_name', (string) config('auth.defaults.guard', 'web'))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        /** @var CarbonInterface|null $deactivatedAt */
        $deactivatedAt = $user->deactivated_at;
        /** @var CarbonInterface|null $verifiedAt */
        $verifiedAt = $user->email_verified_at;
        /** @var CarbonInterface|null $createdAt */
        $createdAt = $user->created_at;

        return [
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'deactivated_at' => $deactivatedAt?->toIso8601String(),
            'email_verified_at' => $verifiedAt?->toIso8601String(),
            'created_at' => $createdAt?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $users = $this->orgUsers($request)->orderBy('name')->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => $this->userPayload($user)),
        ]);
    }

    /**
     * Direct user creation by an admin. The admin vouches for the email, so
     * the account is created verified; the shared password policy
     * (min 12 + breached check, C-02) applies.
     */
    public function store(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        /** @var array{name: string, email: string, password: string, role: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
            'role' => ['required', 'string', Rule::in(InvitationService::validRoles())],
        ]);

        if ($this->orgUsers($request)->where('email', $validated['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already in use in your organization.'],
            ]);
        }

        $user = DB::transaction(function () use ($admin, $validated): User {
            $user = User::create([
                'org_id' => $admin->org_id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                // The 'hashed' cast hashes this on set.
                'password' => $validated['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->assignRole($this->roleModel((string) $admin->org_id, $validated['role']));

            AuditLogger::log('user.created', $admin, [
                'actor_id' => (string) $admin->getKey(),
                'org_id' => (string) $admin->org_id,
                'role' => $validated['role'],
            ]);

            return $user;
        });

        return response()->json(['data' => $this->userPayload($user->fresh())], 201);
    }

    public function show(Request $request, string $user): JsonResponse
    {
        // Cross-org → 404 {code: "not_found"} (no existence leak).
        $model = $this->orgUsers($request)->findOrFail($user);

        return response()->json(['data' => $this->userPayload($model)]);
    }

    /**
     * Role assignment. An admin cannot change their own role (self-lockout
     * guard).
     */
    public function update(Request $request, string $user): JsonResponse
    {
        $admin = $this->admin($request);

        /** @var array{role: string} $validated */
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(InvitationService::validRoles())],
        ]);

        $model = $this->orgUsers($request)->findOrFail($user);

        if ((string) $model->getKey() === (string) $admin->getKey()) {
            throw ValidationException::withMessages([
                'role' => ['You cannot change your own role.'],
            ]);
        }

        DB::transaction(function () use ($admin, $model, $validated): void {
            $model->syncRoles([$this->roleModel((string) $admin->org_id, $validated['role'])]);

            AuditLogger::log('user.role.assigned', $admin, [
                'actor_id' => (string) $admin->getKey(),
                'target_user_id' => (string) $model->getKey(),
                'role' => $validated['role'],
                'granted_by' => (string) $admin->getKey(),
            ]);
        });

        return response()->json(['data' => $this->userPayload($model->fresh())]);
    }

    /**
     * Deactivation (never hard-delete). The account can no longer log in
     * (AttemptLogin refuses deactivated_at) and all of its sessions are
     * revoked. An admin cannot deactivate their own account.
     */
    public function destroy(Request $request, string $user): Response
    {
        $admin = $this->admin($request);

        $model = $this->orgUsers($request)->findOrFail($user);

        if ((string) $model->getKey() === (string) $admin->getKey()) {
            throw ValidationException::withMessages([
                'user' => ['You cannot deactivate your own account.'],
            ]);
        }

        DB::transaction(function () use ($admin, $model): void {
            $model->forceFill(['deactivated_at' => now()])->save();

            $revoked = DB::table('sessions')
                ->where('user_id', $model->getKey())
                ->delete();

            AuditLogger::log('user.deactivated', $admin, [
                'actor_id' => (string) $admin->getKey(),
                'target_user_id' => (string) $model->getKey(),
                'deactivated_by' => (string) $admin->getKey(),
            ]);

            AuditLogger::log('session.revoked', $admin, [
                'actor_id' => (string) $admin->getKey(),
                'revoked_count' => $revoked,
            ]);
        });

        return response()->noContent();
    }
}
