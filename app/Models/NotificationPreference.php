<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's answer to "tell me about this, here, at these hours".
 *
 * Rows are scoped from broad to narrow — blanket, then category, then category
 * and channel — and the narrowest row that says anything wins. See
 * App\Services\Notifications\NotificationPreferences for the resolution.
 */
class NotificationPreference extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * '' means "everything", and it must be written explicitly: Model::create()
     * does not apply the database default, and a null here would break the
     * unique index, because MySQL counts two NULLs as different values.
     */
    protected $attributes = [
        'category' => '',
        'channel' => '',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** How narrow this row is. Higher wins. */
    public function specificity(): int
    {
        return ($this->category !== '' ? 2 : 0) + ($this->channel !== '' ? 1 : 0);
    }
}
