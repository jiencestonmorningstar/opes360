<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §60 — the receipt for a background ZIP bundle request.
 *
 * See the migration for why this table exists at all: it is only ever
 * written to when a real queue worker is running. On `sync` a bundle
 * download never creates one of these — see DocumentBundles::request().
 */
class BusinessDocumentBundle extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ready_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
