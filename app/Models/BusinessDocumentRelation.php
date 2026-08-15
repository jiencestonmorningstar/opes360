<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One link between a document and an ERP record.
 *
 * The document is owned by Documents. The thing it points at is owned by
 * whichever module it came from, and nothing here reaches into it beyond
 * resolving the morph.
 */
class BusinessDocumentRelation extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /** What a link can mean. */
    public const ROLES = [
        'about' => 'About',
        'supporting' => 'Supporting document',
        'signed_by' => 'Signed by',
        'supersedes' => 'Supersedes',
        'attachment' => 'Attachment',
    ];

    protected $guarded = ['id'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    /**
     * The ERP record. May resolve to null — the row it pointed at can be
     * deleted, and a document surviving its subject is the intended behaviour,
     * not a broken link to repair.
     */
    public function related(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'related_type', 'related_id');
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? 'Related';
    }
}
