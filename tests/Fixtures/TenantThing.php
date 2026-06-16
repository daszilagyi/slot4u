<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only tenant-owned model: proves the BelongsToTenant trait works as a
 * single-line opt-in before any real tenant model exists. Its table is created
 * inline in the tenancy tests' beforeEach.
 */
class TenantThing extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_things';

    protected $fillable = ['tenant_id', 'name'];
}
