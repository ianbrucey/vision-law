<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the five-role permission matrix from 03-contract.md (single source of
 * truth). Roles are org-scoped (001-D02); spatie permissions are global
 * capability names. Matter-scoped actions are governed by matter_grants, not
 * by spatie permissions. Idempotent: safe to re-run.
 */
class PermissionMatrixSeeder extends Seeder
{
    /**
     * Capability permissions from the 03-contract.md permission matrix.
     *
     * @var array<string, string> permission name => description
     */
    public const PERMISSIONS = [
        'org.users.manage' => 'Manage org users/roles/teams/invitations',
        'org.audit.view' => 'View audit log',
        'org.matters.create' => 'Create matter (stub: title/number)',
        'org.directory.view' => 'List org user directory',
        'org.templates.publish' => 'Publish document templates',
        'org.holds.manage' => 'Place and release legal holds',
    ];

    /**
     * Role => capability permission names, per the 03-contract.md matrix.
     * outside_counsel is default-deny: no org-level permissions; matter access
     * only via explicit grants.
     *
     * @var array<string, list<string>>
     */
    public const MATRIX = [
        'org_admin' => ['org.users.manage', 'org.audit.view', 'org.matters.create', 'org.directory.view'],
        'attorney' => ['org.matters.create', 'org.directory.view'],
        'paralegal' => ['org.directory.view'],
        'outside_counsel' => [],
        'viewer' => ['org.directory.view'],
        // 007 T-04: publishing a template (stationery) requires this role;
        // anyone in the org may create drafts and generate from published
        // templates. Publishing stays 403 for actors without the role.
        'template_editor' => ['org.templates.publish'],
        // 007 T-09: placing or releasing a legal hold requires this
        // role; release additionally requires a logged reason. 403
        // otherwise (DOC-28).
        'legal_hold' => ['org.holds.manage'],
    ];

    /**
     * Seed roles + permissions for one organization (idempotent).
     */
    public static function seedFor(Organization $org): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        $permissions = [];
        foreach (self::PERMISSIONS as $name => $description) {
            $permissions[$name] = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }

        foreach (self::MATRIX as $roleName => $permissionNames) {
            $role = Role::firstOrCreate([
                'org_id' => $org->getKey(),
                'name' => $roleName,
                'guard_name' => $guard,
            ]);

            $role->syncPermissions(array_map(
                fn (string $name) => $permissions[$name],
                $permissionNames
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function run(): void
    {
        Organization::query()->each(fn (Organization $org) => self::seedFor($org));
    }
}
