<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One language's body for a business's own template — §44.
 *
 * A template with no rows here behaves exactly as it always did: its own
 * `body` column is the only text there is. Rows here are additional
 * variants DocumentComposer picks between; they never replace `body`.
 */
class BusinessDocumentTemplateTranslation extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentTemplate::class, 'business_document_template_id');
    }
}
