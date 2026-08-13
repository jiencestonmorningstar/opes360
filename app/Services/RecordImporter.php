<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Item;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Bringing an existing business's records in.
 *
 * A business switching to this app already has its customers in a spreadsheet,
 * a notebook or another system's export. Making them retype it is how a
 * migration stalls on day one, so the parser is deliberately forgiving about
 * column names — the same reasoning as the bank statement importer, and for
 * the same reason: a business that has to rename headers before it can import
 * will do it once and then give up.
 *
 * Two passes, always:
 *
 *   preview() reads the file and reports what it found, what it would skip and
 *   why — nothing is written;
 *   commit()  writes the rows the preview showed.
 *
 * Splitting them is the whole point. An import that writes first and reports
 * afterwards leaves a business picking 300 half-right rows out of its customer
 * book by hand.
 */
class RecordImporter
{
    public const TYPES = [
        'customers' => 'Customers',
        'products' => 'Products',
    ];

    /** How many rows a single import may carry. */
    public const MAX_ROWS = 2000;

    /**
     * Column aliases, in the languages and spellings these files actually
     * arrive in. Normalised to lowercase letters only before matching, so
     * "Phone Number", "phone_number" and "PHONE-NUMBER" are one thing.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    protected const COLUMNS = [
        'customers' => [
            'name' => ['name', 'customername', 'client', 'clientname', 'nom', 'fullname', 'contact'],
            'company_name' => ['company', 'companyname', 'business', 'entreprise', 'societe'],
            'email' => ['email', 'emailaddress', 'mail', 'courriel'],
            'phone' => ['phone', 'phonenumber', 'telephone', 'tel', 'mobile', 'contactnumber', 'numero'],
            'whatsapp' => ['whatsapp', 'whatsappnumber'],
            'city' => ['city', 'town', 'ville'],
            'street' => ['street', 'address', 'addresse', 'adresse', 'streetaddress'],
            'tax_id' => ['taxid', 'niu', 'vatnumber', 'tva', 'numerocontribuable'],
            'notes' => ['notes', 'note', 'comment', 'comments', 'remarks'],
        ],
        'products' => [
            'name' => ['name', 'productname', 'item', 'itemname', 'description', 'produit', 'designation', 'libelle'],
            'sku' => ['sku', 'code', 'productcode', 'itemcode', 'reference', 'ref'],
            'barcode' => ['barcode', 'ean', 'upc', 'codebarre'],
            'price' => ['price', 'sellingprice', 'unitprice', 'prix', 'prixdevente', 'pu'],
            'cost' => ['cost', 'costprice', 'buyingprice', 'purchaseprice', 'cout', 'prixdachat'],
            'unit' => ['unit', 'uom', 'unite', 'measure'],
            'stock' => ['stock', 'quantity', 'qty', 'quantite', 'onhand', 'openingstock'],
        ],
    ];

    /**
     * Read a file and say what would happen, without writing anything.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     skipped: array<int, array{line: int, reason: string}>,
     *     matched: array<int, string>,
     *     unmatched: array<int, string>,
     * }
     */
    public function preview(string $type, string $contents): array
    {
        $this->assertType($type);

        [$columns, $records] = $this->readTable($contents);

        $map = $this->mapColumns($type, $columns);

        if (! isset($map['name'])) {
            throw new RuntimeException(
                'This file needs a column naming each '.
                ($type === 'products' ? 'product' : 'customer').
                '. None of its headers looked like one.'
            );
        }

        $rows = [];
        $skipped = [];
        $seen = [];

        foreach ($records as $offset => $record) {
            // +2: one for the header row, one because humans count from 1.
            $line = $offset + 2;

            $row = [];

            foreach ($map as $field => $index) {
                $row[$field] = trim((string) ($record[$index] ?? ''));
            }

            if ($row['name'] === '') {
                $skipped[] = ['line' => $line, 'reason' => 'No name in this row.'];

                continue;
            }

            // Within-file duplicates, caught before they become two records.
            $key = mb_strtolower($row['name']);

            if (isset($seen[$key])) {
                $skipped[] = ['line' => $line, 'reason' => 'Same name as line '.$seen[$key].'.'];

                continue;
            }

            $seen[$key] = $line;

            if (count($rows) >= self::MAX_ROWS) {
                $skipped[] = ['line' => $line, 'reason' => 'Over the '.number_format(self::MAX_ROWS).' row limit for one import.'];

                continue;
            }

            $row['line'] = $line;
            $rows[] = $row;
        }

        $matchedHeaders = [];
        foreach ($map as $index) {
            $matchedHeaders[] = $columns[$index]['original'];
        }

        $unmatched = [];
        foreach ($columns as $index => $column) {
            if (! in_array($index, $map, true) && $column['original'] !== '') {
                $unmatched[] = $column['original'];
            }
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'matched' => $matchedHeaders,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Write the rows a preview produced.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int}
     */
    public function commit(string $type, array $rows, ?User $actor = null): array
    {
        $this->assertType($type);

        if (app(CurrentCompany::class)->get() === null) {
            throw new RuntimeException('Cannot import without a current company.');
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($type, $rows, $actor, &$created, &$updated) {
            foreach ($rows as $row) {
                $existing = $this->findExisting($type, $row);

                if ($existing !== null) {
                    $existing->fill($this->attributesFor($type, $row, $existing));
                    $existing->save();
                    $updated++;

                    continue;
                }

                $model = $type === 'customers' ? new Contact : new Item;
                $model->fill($this->attributesFor($type, $row));

                if ($actor !== null) {
                    $model->created_by = $actor->id;
                }

                $model->save();
                $created++;
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * An import run twice must not double the customer book, so an existing
     * record is matched and updated rather than added again. SKU first for
     * products because it is the thing that is actually unique; name is the
     * fallback because most small-business exports have nothing else.
     */
    protected function findExisting(string $type, array $row)
    {
        if ($type === 'products') {
            if (($row['sku'] ?? '') !== '') {
                $bySku = Item::where('sku', $row['sku'])->first();

                if ($bySku !== null) {
                    return $bySku;
                }
            }

            return Item::where('name', $row['name'])->first();
        }

        if (($row['email'] ?? '') !== '') {
            $byEmail = Contact::where('email', $row['email'])->first();

            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        return Contact::where('name', $row['name'])->first();
    }

    /** @return array<string, mixed> */
    protected function attributesFor(string $type, array $row, $existing = null): array
    {
        if ($type === 'products') {
            $attributes = [
                'name' => $row['name'],
                'type' => 'product',
                'is_active' => true,
            ];

            foreach (['sku', 'barcode', 'unit'] as $field) {
                if (($row[$field] ?? '') !== '') {
                    $attributes[$field] = $row[$field];
                }
            }

            foreach (['price', 'cost'] as $field) {
                if (($row[$field] ?? '') !== '') {
                    $attributes[$field] = $this->parseAmount($row[$field]);
                }
            }

            return $attributes;
        }

        $attributes = [
            'name' => $row['name'],
            'type' => 'customer',
        ];

        foreach (['company_name', 'email', 'whatsapp', 'tax_id', 'notes'] as $field) {
            if (($row[$field] ?? '') !== '') {
                $attributes[$field] = $row[$field];
            }
        }

        if (($row['phone'] ?? '') !== '') {
            $attributes['phones'] = [$row['phone']];
        }

        $address = array_filter([
            'street' => $row['street'] ?? '',
            'city' => $row['city'] ?? '',
        ], fn ($v) => $v !== '');

        if ($address !== []) {
            // Merge rather than replace: a file with only a city must not wipe
            // a street somebody typed in by hand.
            $attributes['address'] = array_merge((array) ($existing?->address ?? []), $address);
        }

        return $attributes;
    }

    /**
     * Rows out of a file, whatever shape it arrived in.
     *
     * A business's records are in an .xlsx far more often than a .csv, and
     * "export to CSV first" is a step people get wrong or refuse. The file is
     * sniffed by content rather than trusted by extension, because a file
     * named .csv that is really a workbook is a common way this fails.
     *
     * @return array{0: array<int, array{normalised: string, original: string}>, 1: array<int, array<int, string>>}
     */
    protected function readTable(string $contents): array
    {
        $rows = $this->looksLikeSpreadsheet($contents)
            ? $this->readSpreadsheet($contents)
            : $this->readCsv($contents);

        $header = array_shift($rows);

        if ($header === null) {
            throw new RuntimeException('That file has no rows in it.');
        }

        $columns = [];

        foreach ($header as $name) {
            $columns[] = [
                'normalised' => preg_replace('/[^a-z]/', '', mb_strtolower((string) $name)),
                'original' => trim((string) $name),
            ];
        }

        // A spreadsheet's trailing formatting leaves rows that are entirely
        // empty; they are not data and must not count as skipped rows either.
        $records = array_values(array_filter(
            $rows,
            fn ($row) => implode('', array_map('strval', $row)) !== ''
        ));

        return [$columns, $records];
    }

    /**
     * xlsx is a zip, and every zip starts "PK\x03\x04". The old binary .xls
     * has its own signature; neither is valid UTF-8 text, so this cannot
     * misfire on a real CSV.
     */
    protected function looksLikeSpreadsheet(string $contents): bool
    {
        return str_starts_with($contents, "PK\x03\x04")
            || str_starts_with($contents, "\xD0\xCF\x11\xE0");
    }

    /** @return array<int, array<int, string>> */
    protected function readSpreadsheet(string $contents): array
    {
        // PhpSpreadsheet reads from a path, so the upload has to land on disk.
        $path = tempnam(sys_get_temp_dir(), 'opes-import-');
        file_put_contents($path, $contents);

        try {
            $reader = IOFactory::createReaderForFile($path);
            // Formatting, print areas and charts are irrelevant here and are
            // the expensive part of opening a workbook.
            $reader->setReadDataOnly(true);

            $sheet = $reader->load($path)->getActiveSheet();

            $rows = [];

            foreach ($sheet->toArray(null, true, false, false) as $row) {
                $rows[] = array_map(fn ($cell) => $this->cellToString($cell), $row);
            }

            return $rows;
        } catch (SpreadsheetException $e) {
            throw new RuntimeException('That spreadsheet could not be opened. Try saving it again as .xlsx or .csv.');
        } finally {
            @unlink($path);
        }
    }

    /**
     * A spreadsheet cell as text.
     *
     * Excel stores a phone number or a long SKU as a number whenever it can,
     * and PHP renders a large float in scientific notation — so a customer's
     * number would import as "2.3767E+11" without this. Whole numbers are
     * printed in full; anything else keeps its decimals.
     */
    protected function cellToString(mixed $cell): string
    {
        if (is_float($cell) || is_int($cell)) {
            return trim(rtrim(rtrim(number_format((float) $cell, 6, '.', ''), '0'), '.'));
        }

        return trim((string) $cell);
    }

    /** @return array<int, array<int, string>> */
    protected function readCsv(string $contents): array
    {
        // Excel writes a BOM on "CSV UTF-8", and it lands inside the first
        // header, so `Name` never matches until it is removed.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];

        while (($record = fgetcsv($handle)) !== false) {
            if ($record === [null]) {
                continue;
            }

            $rows[] = $record;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, array{normalised: string, original: string}>  $columns
     * @return array<string, int>
     */
    protected function mapColumns(string $type, array $columns): array
    {
        $map = [];

        foreach (self::COLUMNS[$type] as $field => $aliases) {
            foreach ($aliases as $alias) {
                foreach ($columns as $index => $column) {
                    if ($column['normalised'] === $alias && ! in_array($index, $map, true)) {
                        $map[$field] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Amounts arrive as "1 250 000", "1,250,000" and "1.250.000,50" depending
     * on who exported them. Treat the last separator as the decimal point only
     * when it is followed by one or two digits.
     */
    protected function parseAmount(string $value): float
    {
        $value = preg_replace('/[^0-9,.\-]/', '', $value);

        if ($value === '' || $value === '-') {
            return 0.0;
        }

        if (preg_match('/[.,](\d{1,2})$/', $value, $matches)) {
            $decimals = $matches[1];
            $whole = preg_replace('/[^0-9\-]/', '', mb_substr($value, 0, -(mb_strlen($decimals) + 1)));

            return (float) ($whole.'.'.$decimals);
        }

        return (float) preg_replace('/[^0-9\-]/', '', $value);
    }

    protected function assertType(string $type): void
    {
        if (! array_key_exists($type, self::TYPES)) {
            throw new InvalidArgumentException("Unknown import type [{$type}].");
        }
    }
}
