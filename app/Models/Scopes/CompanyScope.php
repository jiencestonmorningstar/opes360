<?php

namespace App\Models\Scopes;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current company.
 *
 * Applied by the BelongsToCompany trait rather than model-by-model, so a new
 * model cannot accidentally ship without it.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentCompany::class);

        if ($current->isSuspended()) {
            return;
        }

        // No current company means no tenant data is visible. Failing closed is
        // the only safe default: a missing company must never widen a query to
        // every tenant in the database.
        $builder->where(
            $model->qualifyColumn('company_id'),
            $current->id()
        );
    }
}
