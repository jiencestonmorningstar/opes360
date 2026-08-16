<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A row in the global search index. See App\Search\GlobalSearch for what gets
 * indexed and — more importantly — what deliberately does not.
 */
class SearchEntry extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'route_params' => 'array',
        ];
    }

    public function searchable(): MorphTo
    {
        return $this->morphTo();
    }
}
