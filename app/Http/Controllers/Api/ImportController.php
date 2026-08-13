<?php

namespace App\Http\Controllers\Api;

use App\Services\RecordImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Importing records over the API.
 *
 * The same two steps the screen uses, for the same reason: `preview` reports
 * what would happen and writes nothing, `store` writes it. A caller that skips
 * straight to `store` is choosing that, rather than having it chosen for them
 * by an endpoint that only had one mode.
 */
class ImportController extends ApiController
{
    public function __construct(private readonly RecordImporter $importer) {}

    public function preview(Request $request): JsonResponse
    {
        $type = $this->validated($request);

        try {
            $result = $this->importer->preview(
                $type,
                file_get_contents($request->file('file')->getRealPath())
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = $this->validated($request);

        try {
            $preview = $this->importer->preview(
                $type,
                file_get_contents($request->file('file')->getRealPath())
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result = $this->importer->commit($type, $preview['rows'], $request->user());

        return response()->json([
            'data' => $result + [
                'skipped' => count($preview['skipped']),
                'ignored_columns' => $preview['unmatched'],
            ],
        ], 201);
    }

    /** Validates the request and returns the import type. */
    private function validated(Request $request): string
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(RecordImporter::TYPES))],
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx,xls'],
        ]);

        // The import writes the records the create endpoints write, so it asks
        // for the same permission rather than inventing an import one.
        Gate::authorize($data['type'] === 'products' ? 'products.create' : 'customers.create');

        return $data['type'];
    }
}
