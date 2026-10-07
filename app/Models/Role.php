<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Org-scoped spatie role (001-D02: roles carry org_id; spatie's teams feature
 * is NOT used). UUID primary key via HasUuids — required because spatie's
 * base model assumes an incrementing key and reads back a bogus
 * lastInsertId() on Postgres UUID columns.
 */
class Role extends SpatieRole
{
    use HasUuids;
}
