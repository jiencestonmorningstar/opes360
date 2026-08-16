<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a business requires before an ERP record is considered complete —
 * "a supplier needs a registration document, a tax certificate and a signed
 * agreement."
 *
 * Names document *kinds*. Whether each is satisfied is answered by looking
 * at what is actually linked to the record, never by a second stored flag —
 * a "complete" tick that could disagree with the documents underneath it
 * would be worse than no checklist at all.
 */
class BusinessDocumentChecklist extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['required_kinds' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForSubjectType(Builder $query, string $subjectType): Builder
    {
        return $query->where('subject_type', $subjectType);
    }
}
