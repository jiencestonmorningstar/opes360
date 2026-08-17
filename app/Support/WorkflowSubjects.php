<?php

namespace App\Support;

use App\Models\BusinessDocument;
use App\Models\ComplianceFiling;
use App\Models\Contract;
use App\Models\ExpenseClaim;
use App\Models\InsuranceClaim;
use App\Models\JobOffer;
use App\Models\PurchaseRequisition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a workflow can be written about, in words a business recognises.
 *
 * The engine stores a class name, which is right — it must never import a
 * business model, and a module must never import the engine's internals. But
 * "App\Models\PurchaseRequisition" is not a thing anybody asked to approve, so
 * the screens need somewhere to turn it into "Purchase requests".
 *
 * A list rather than a scan of every model with the Approvable trait. A screen
 * offering approval on something nobody meant to be approvable is a way to
 * build a workflow that quietly never runs, and finding out takes weeks.
 */
class WorkflowSubjects
{
    /** @var array<class-string, array{label: string, hint: string}> */
    public const CATALOGUE = [
        PurchaseRequisition::class => [
            'label' => 'Purchase requests',
            'hint' => 'Somebody asking to buy something, before the money is committed.',
        ],
        ExpenseClaim::class => [
            'label' => 'Staff expense claims',
            'hint' => 'Money an employee paid themselves and wants back.',
        ],
        Contract::class => [
            'label' => 'Contracts',
            'hint' => 'Signing the business up to something.',
        ],
        ComplianceFiling::class => [
            'label' => 'Statutory filings',
            'hint' => 'Swearing that a return went in. The filer should not be the only witness.',
        ],
        BusinessDocument::class => [
            'label' => 'Documents',
            'hint' => 'Letters, memos and anything else issued on the company’s paper.',
        ],
        JobOffer::class => [
            'label' => 'Job offers',
            'hint' => 'An offer letter commits a salary every month from now on.',
        ],
        InsuranceClaim::class => [
            'label' => 'Claim settlements',
            'hint' => 'Paying out on an insurance claim, before the money leaves.',
        ],
    ];

    /**
     * Columns that exist on every tenant table and mean nothing to a rule.
     *
     * Offering `company_id` as something to compare against would let an
     * administrator write a condition that is always true or always false and
     * looks deliberate, which is worse than not offering it.
     */
    protected const NOISE = [
        'id', 'company_id', 'created_at', 'updated_at', 'deleted_at',
        'created_by', 'updated_by', 'ulid', 'uuid',
    ];

    /** @return array<class-string, string> */
    public static function options(): array
    {
        return array_map(fn (array $entry) => $entry['label'], self::CATALOGUE);
    }

    public static function label(?string $subjectType): string
    {
        if ($subjectType === null) {
            return 'Nothing yet';
        }

        return self::CATALOGUE[$subjectType]['label']
            ?? Str::headline(class_basename($subjectType));
    }

    public static function hint(?string $subjectType): ?string
    {
        return $subjectType === null ? null : (self::CATALOGUE[$subjectType]['hint'] ?? null);
    }

    public static function isKnown(?string $subjectType): bool
    {
        return $subjectType !== null && array_key_exists($subjectType, self::CATALOGUE);
    }

    /**
     * What a condition on this subject may be written about.
     *
     * Read off the actual table, because WorkflowConditions checks the field
     * against the record's real attributes and fails closed on anything else.
     * A field list typed by hand here would drift from the table and the only
     * symptom would be steps silently skipped — the failure nobody reports,
     * because it looks like the approval simply was not needed.
     *
     * @return array<int, string>
     */
    public static function fields(?string $subjectType): array
    {
        if (! self::isKnown($subjectType)) {
            return [];
        }

        try {
            /** @var Model $model */
            $model = new $subjectType;

            $columns = Schema::getColumnListing($model->getTable());
        } catch (Throwable) {
            // A module whose table has not been migrated is not worth an error
            // page: the screen still works, it just cannot suggest fields.
            return [];
        }

        return collect($columns)
            ->reject(fn (string $column) => in_array($column, self::NOISE, true))
            ->values()
            ->all();
    }
}
