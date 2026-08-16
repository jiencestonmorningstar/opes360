<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An offer of employment, awaiting the business's own sign-off and then the
 * candidate's answer.
 *
 * Approvable + EmitsDomainEvents: the offer is handed to the shared
 * WorkflowEngine like every other approvable record, and the engine announces
 * `workflow.approved` through this model. `status` mirrors the verdict for
 * lists (see MarkApprovedJobOffers), but acceptance re-checks the engine via
 * isApproved() — the instance, not the mirror, is the authority.
 */
class JobOffer extends Model
{
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft',
        'pending' => 'Awaiting approval',
        'approved' => 'Approved',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'rejected' => 'Not approved',
        'withdrawn' => 'Withdrawn',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'starts_on' => 'date',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    /** The generated offer letter. */
    public function letter(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }
}
