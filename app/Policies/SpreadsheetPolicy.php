<?php

namespace App\Policies;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Support\CurrentCompany;

/**
 * Gated on `accounting.view`/`accounting.manage` rather than a new
 * permission group of its own — a formula cell can read financial
 * aggregates (`OPES_SUM`/`OPES_LOOKUP` against invoices, contracts), so the
 * bar is the same one that already gates seeing the ledger, not the wider
 * "any company member" bar comments use. Per-source permission finer than
 * that (e.g. only Accounting may query payroll) is a designed extension
 * point on `SpreadsheetDataSources`, not populated yet — see the
 * collaborative-editor roadmap plan.
 */
class SpreadsheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.view');
    }

    public function view(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->owns($spreadsheet) && $user->can('accounting.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounting.view');
    }

    public function update(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->owns($spreadsheet) && $user->can('accounting.view');
    }

    public function delete(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->owns($spreadsheet) && $user->can('accounting.manage');
    }

    protected function owns(Spreadsheet $spreadsheet): bool
    {
        $current = app(CurrentCompany::class)->id();

        return $current !== null && $spreadsheet->company_id === $current;
    }
}
