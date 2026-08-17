<?php

namespace App\Services\Spreadsheets;

use App\Models\Company;
use RuntimeException;

/**
 * Evaluates one cell's formula — §8.2 of the master spec's `=OPES_SUM(...)`
 * / `=OPES_LOOKUP(...)` cells, plus plain arithmetic and cell references
 * (`=A1+B2*2`). A small hand-written recursive-descent parser rather than a
 * dependency: the grammar is deliberately tiny (numbers, strings, cell
 * refs, the four arithmetic operators, parentheses, function calls) and stays easy to audit for
 * exactly what it can and cannot reach — nothing here ever executes SQL
 * built from formula text; OPES_SUM/OPES_LOOKUP validate every argument
 * against SpreadsheetDataSources' allowlist before touching a query.
 *
 * A formula starts with `=`; anything else is a literal (parsed as a number
 * when it looks like one, otherwise left as the string it is), exactly like
 * every spreadsheet a person has already used.
 */
class FormulaEngine
{
    /** @var array<int, array{type: string, value: string}> */
    protected array $tokens = [];

    protected int $pos = 0;

    public function __construct(protected SpreadsheetDataSources $sources) {}

    /**
     * @param  callable(string): mixed  $resolveCell  Given "A1", returns
     *                                                 that cell's already-
     *                                                 evaluated value.
     *                                                 Cycle detection is the
     *                                                 caller's job
     *                                                 (SpreadsheetEngine) —
     *                                                 this class only ever
     *                                                 evaluates one formula
     *                                                 at a time.
     */
    public function evaluate(string $raw, Company $company, callable $resolveCell): int|float|string|null
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (! str_starts_with($raw, '=')) {
            return is_numeric($raw) ? $raw + 0 : $raw;
        }

        $this->tokens = $this->tokenize(substr($raw, 1));
        $this->pos = 0;

        $result = $this->expression($company, $resolveCell);

        if ($this->tokens[$this->pos]['type'] !== 'eof') {
            throw new RuntimeException('Unexpected input after formula: '.$this->tokens[$this->pos]['value']);
        }

        return $result;
    }

    /** @return array<int, array{type: string, value: string}> */
    protected function tokenize(string $src): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($src);

        while ($i < $len) {
            $char = $src[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if ($char === '"') {
                $j = $i + 1;
                $value = '';

                while ($j < $len && $src[$j] !== '"') {
                    $value .= $src[$j];
                    $j++;
                }

                $tokens[] = ['type' => 'string', 'value' => $value];
                $i = $j + 1;

                continue;
            }

            if (is_numeric($char) || ($char === '.' && $i + 1 < $len && is_numeric($src[$i + 1]))) {
                $j = $i;

                while ($j < $len && (is_numeric($src[$j]) || $src[$j] === '.')) {
                    $j++;
                }

                $tokens[] = ['type' => 'number', 'value' => substr($src, $i, $j - $i)];
                $i = $j;

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $j = $i;

                while ($j < $len && (ctype_alnum($src[$j]) || $src[$j] === '_')) {
                    $j++;
                }

                $tokens[] = ['type' => 'ident', 'value' => substr($src, $i, $j - $i)];
                $i = $j;

                continue;
            }

            $simple = ['+' => 'plus', '-' => 'minus', '*' => 'star', '/' => 'slash', '(' => 'lparen', ')' => 'rparen', ',' => 'comma'];

            if (isset($simple[$char])) {
                $tokens[] = ['type' => $simple[$char], 'value' => $char];
                $i++;

                continue;
            }

            throw new RuntimeException("Unrecognised character in formula: '{$char}'");
        }

        $tokens[] = ['type' => 'eof', 'value' => ''];

        return $tokens;
    }

    protected function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    protected function advance(): array
    {
        return $this->tokens[$this->pos++];
    }

    /** @param  callable(string): mixed  $resolveCell */
    protected function expression(Company $company, callable $resolveCell): int|float|string|null
    {
        $value = $this->term($company, $resolveCell);

        while (in_array($this->peek()['type'], ['plus', 'minus'], true)) {
            $op = $this->advance()['type'];
            $right = $this->term($company, $resolveCell);
            $value = $op === 'plus' ? $this->numeric($value) + $this->numeric($right) : $this->numeric($value) - $this->numeric($right);
        }

        return $value;
    }

    /** @param  callable(string): mixed  $resolveCell */
    protected function term(Company $company, callable $resolveCell): int|float|string|null
    {
        $value = $this->factor($company, $resolveCell);

        while (in_array($this->peek()['type'], ['star', 'slash'], true)) {
            $op = $this->advance()['type'];
            $right = $this->factor($company, $resolveCell);

            if ($op === 'slash' && $this->numeric($right) == 0) {
                throw new RuntimeException('Division by zero.');
            }

            $value = $op === 'star' ? $this->numeric($value) * $this->numeric($right) : $this->numeric($value) / $this->numeric($right);
        }

        return $value;
    }

    /** @param  callable(string): mixed  $resolveCell */
    protected function factor(Company $company, callable $resolveCell): int|float|string|null
    {
        $token = $this->peek();

        if ($token['type'] === 'minus') {
            $this->advance();

            return -1 * $this->numeric($this->factor($company, $resolveCell));
        }

        if ($token['type'] === 'number') {
            $this->advance();

            return str_contains($token['value'], '.') ? (float) $token['value'] : (int) $token['value'];
        }

        if ($token['type'] === 'string') {
            $this->advance();

            return $token['value'];
        }

        if ($token['type'] === 'lparen') {
            $this->advance();
            $value = $this->expression($company, $resolveCell);
            $this->expect('rparen');

            return $value;
        }

        if ($token['type'] === 'ident') {
            $this->advance();

            if ($this->peek()['type'] === 'lparen') {
                return $this->functionCall($token['value'], $company, $resolveCell);
            }

            if (preg_match('/^[A-Za-z]+[0-9]+$/', $token['value']) === 1) {
                return $resolveCell(strtoupper($token['value']));
            }

            throw new RuntimeException("Unknown reference: {$token['value']}");
        }

        throw new RuntimeException('Unexpected token in formula: '.$token['value']);
    }

    /** @param  callable(string): mixed  $resolveCell */
    protected function functionCall(string $name, Company $company, callable $resolveCell): int|float|string|null
    {
        $this->expect('lparen');

        $args = [];

        if ($this->peek()['type'] !== 'rparen') {
            $args[] = $this->expression($company, $resolveCell);

            while ($this->peek()['type'] === 'comma') {
                $this->advance();
                $args[] = $this->expression($company, $resolveCell);
            }
        }

        $this->expect('rparen');

        return match (strtoupper($name)) {
            'OPES_SUM' => $this->opesSum($args, $company),
            'OPES_LOOKUP' => $this->opesLookup($args, $company),
            default => throw new RuntimeException("Unknown function: {$name}"),
        };
    }

    /**
     * Two call shapes: `OPES_SUM("source","field")` sums unfiltered;
     * `OPES_SUM("source","filterField","filterValue","sumField")` filters
     * first. Both read only allowlisted columns.
     *
     * @param  array<int, mixed>  $args
     */
    protected function opesSum(array $args, Company $company): int|float
    {
        $source = (string) ($args[0] ?? '');

        if (! $this->sources->has($source)) {
            throw new RuntimeException("OPES_SUM: unknown source \"{$source}\".");
        }

        if (count($args) === 2) {
            $sumField = (string) $args[1];
            $filterField = null;
            $filterValue = null;
        } elseif (count($args) === 4) {
            $filterField = (string) $args[1];
            $filterValue = $args[2];
            $sumField = (string) $args[3];
        } else {
            throw new RuntimeException(
                'OPES_SUM takes 2 or 4 arguments: source,field or source,filterField,filterValue,sumField.'
            );
        }

        if (! $this->sources->sumFieldAllowed($source, $sumField)) {
            throw new RuntimeException("OPES_SUM: \"{$sumField}\" is not a summable field on \"{$source}\".");
        }

        $query = $this->sources->queryFor($source, $company);

        if ($filterField !== null) {
            $query->where($filterField, $filterValue);
        }

        return $query->sum($sumField) + 0;
    }

    /** @param  array<int, mixed>  $args */
    protected function opesLookup(array $args, Company $company): int|float|string|null
    {
        $source = (string) ($args[0] ?? '');
        $keyValue = $args[1] ?? null;
        $returnField = (string) ($args[2] ?? '');

        if (! $this->sources->has($source)) {
            throw new RuntimeException("OPES_LOOKUP: unknown source \"{$source}\".");
        }

        if (! $this->sources->lookupFieldAllowed($source, $returnField)) {
            throw new RuntimeException("OPES_LOOKUP: \"{$returnField}\" is not a readable field on \"{$source}\".");
        }

        $keyField = $this->sources->keyFieldFor($source);
        $row = $this->sources->queryFor($source, $company)->where($keyField, $keyValue)->first();

        if ($row === null) {
            return null;
        }

        $value = $row->getAttribute($returnField);

        return is_numeric($value) ? $value + 0 : $value;
    }

    protected function numeric(mixed $value): int|float
    {
        if (is_numeric($value)) {
            return $value + 0;
        }

        throw new RuntimeException('Expected a number, got: '.var_export($value, true));
    }

    protected function expect(string $type): void
    {
        if ($this->peek()['type'] !== $type) {
            throw new RuntimeException("Expected {$type}, got {$this->peek()['type']}.");
        }

        $this->advance();
    }
}
