<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Spatie permission with a UUID primary key (see App\Models\Role for why
 * HasUuids is required on Postgres).
 */
class Permission extends SpatiePermission
{
    use HasUuids;
}
