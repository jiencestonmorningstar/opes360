<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One submission of a form. Answers are stored keyed by field id, so a field
 * renamed or removed after the fact never corrupts what was submitted.
 */
class FormResponse extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answers' => 'array'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** The stored answer for a field, flattened for display. */
    public function answerFor(string $fieldId): string
    {
        $value = $this->answers[$fieldId] ?? null;

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return (string) ($value ?? '');
    }
}
