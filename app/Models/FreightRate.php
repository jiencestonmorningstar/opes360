<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One route's price: per-kilogram and a floor.
 *
 * A proposal engine, not a price law — booking reads the card to prefill the
 * freight figure and the clerk decides. See RateCards::quote for the one
 * piece of arithmetic.
 */
class FreightRate extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'per_kg' => 'decimal:2',
            'minimum' => 'decimal:2',
        ];
    }

    public function routeLabel(): string
    {
        return $this->from_location.' → '.$this->to_location;
    }
}
