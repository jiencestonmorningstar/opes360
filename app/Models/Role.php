<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $guarded = ['id'];

    /** Ordered most to least powerful; index+1 is the stored level. */
    public const OWNER = 'owner';

    public const ADMINISTRATOR = 'administrator';

    public const MANAGER = 'manager';

    public const ACCOUNTANT = 'accountant';

    public const SALES_OFFICER = 'sales-officer';

    public const CASHIER = 'cashier';

    public const READ_ONLY = 'read-only';

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function hasPermission(string $slug): bool
    {
        return $this->permissions()->where('slug', $slug)->exists();
    }

    /** A user may only assign roles at or below their own level. */
    public function outranks(Role $other): bool
    {
        return $this->level < $other->level;
    }
}
