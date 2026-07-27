<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user permission override within a company. `granted = false` is an explicit
 * revoke that beats the role's grant, which is why this is a model rather than a
 * plain pivot.
 */
class CompanyUserPermission extends Model
{
    protected $table = 'company_user_permission';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
