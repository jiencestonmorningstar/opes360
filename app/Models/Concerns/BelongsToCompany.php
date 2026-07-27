<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Makes a model tenant-owned: scoped on read, stamped on write.
 *
 * Every business table carries company_id and every business model uses this
 * trait. A test asserts that pairing holds, so the two cannot drift apart.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            if ($model->company_id !== null) {
                return;
            }

            $companyId = app(CurrentCompany::class)->id();

            if ($companyId === null) {
                throw new RuntimeException(sprintf(
                    'Cannot create %s without a current company. Set one, or assign company_id explicitly.',
                    static::class
                ));
            }

            $model->company_id = $companyId;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Escape hatch for sync workers, console commands and cross-company owner
     * reporting. Deliberately verbose at the call site so it stands out in review.
     */
    public function scopeAcrossAllCompanies(Builder $query): Builder
    {
        return $query->withoutGlobalScope(CompanyScope::class);
    }
}
