<?php

namespace App\Services\Spreadsheets;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

/**
 * What `OPES_SUM`/`OPES_LOOKUP` (§8.2 of the master spec) are allowed to
 * read — an allowlist registry, the same extension-point shape as
 * `App\Services\Documents\DocumentFieldRegistry`: a module registers its
 * own source once, in a `boot()`, and Spreadsheets never has to know that
 * module exists.
 *
 * Deliberately an allowlist, not a raw table/column name reaching SQL: a
 * formula is user-typed text, and letting it name any column on any model
 * would turn a spreadsheet cell into a query builder for data the person
 * typing it might not be allowed to see (see `sumFields`/`lookupFields`
 * below — only fields explicitly listed here can ever be read, and every
 * query is scoped to the current company by the model's own CompanyScope,
 * exactly like every other read in the product).
 *
 *     app(SpreadsheetDataSources::class)->register('invoices', function (Company $company) {
 *         return Document::query()->invoices();
 *     }, sumFields: ['total', 'balance'], lookupFields: ['total', 'reference'], keyField: 'reference');
 */
class SpreadsheetDataSources
{
    /** @var array<string, array{query: callable(Company): Builder, sumFields: array<int, string>, lookupFields: array<int, string>, keyField: string}> */
    protected array $sources = [];

    /**
     * @param  callable(Company): Builder  $query  The base, company-scoped
     *                                              query a formula is
     *                                              allowed to filter and
     *                                              read from — never the
     *                                              bare model, so a source
     *                                              can pre-scope to "just
     *                                              the invoices" the way
     *                                              `Document::invoices()`
     *                                              already does.
     * @param  array<int, string>  $sumFields  Numeric columns OPES_SUM may total.
     * @param  array<int, string>  $lookupFields  Columns OPES_LOOKUP may return.
     */
    public function register(
        string $name,
        callable $query,
        array $sumFields,
        array $lookupFields,
        string $keyField,
    ): void {
        $this->sources[$name] = [
            'query' => $query,
            'sumFields' => $sumFields,
            'lookupFields' => $lookupFields,
            'keyField' => $keyField,
        ];
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->sources);
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->sources);
    }

    public function queryFor(string $name, Company $company): ?Builder
    {
        if (! $this->has($name)) {
            return null;
        }

        return ($this->sources[$name]['query'])($company);
    }

    public function sumFieldAllowed(string $name, string $field): bool
    {
        return in_array($field, $this->sources[$name]['sumFields'] ?? [], true);
    }

    public function lookupFieldAllowed(string $name, string $field): bool
    {
        return in_array($field, $this->sources[$name]['lookupFields'] ?? [], true);
    }

    public function keyFieldFor(string $name): ?string
    {
        return $this->sources[$name]['keyField'] ?? null;
    }
}
