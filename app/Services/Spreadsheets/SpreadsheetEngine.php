<?php

namespace App\Services\Spreadsheets;

use App\Models\Company;
use App\Models\Spreadsheet;
use RuntimeException;

/**
 * Evaluates every cell in a sheet, resolving `A1`-style references between
 * them. `FormulaEngine` evaluates one formula in isolation; this is what
 * lets `C1` say `=A1+B1` and get the right answer regardless of which order
 * the cells happen to be stored in.
 */
class SpreadsheetEngine
{
    public function __construct(protected FormulaEngine $formulas) {}

    /**
     * @return array<string, int|float|string|null>  ref => computed value,
     *                                                or the string
     *                                                "#ERROR: …" for a cell
     *                                                whose formula failed —
     *                                                a bad formula in one
     *                                                cell never blanks the
     *                                                whole sheet.
     */
    public function evaluate(Spreadsheet $spreadsheet, Company $company): array
    {
        $cells = $spreadsheet->cells ?? [];
        $results = [];
        $resolving = [];

        $resolve = function (string $ref) use (&$resolve, &$results, &$resolving, $cells, $company): mixed {
            $ref = strtoupper($ref);

            if (array_key_exists($ref, $results)) {
                return is_string($results[$ref]) && str_starts_with($results[$ref], '#ERROR') ? 0 : $results[$ref];
            }

            if (isset($resolving[$ref])) {
                throw new RuntimeException('#CIRCULAR');
            }

            $resolving[$ref] = true;

            try {
                $value = $this->formulas->evaluate((string) ($cells[$ref] ?? ''), $company, $resolve);
            } finally {
                unset($resolving[$ref]);
            }

            return $results[$ref] = $value;
        };

        foreach (array_keys($cells) as $ref) {
            if (array_key_exists(strtoupper($ref), $results)) {
                continue;
            }

            try {
                $resolve($ref);
            } catch (RuntimeException $e) {
                $results[strtoupper($ref)] = '#ERROR: '.$e->getMessage();
            }
        }

        return $results;
    }
}
