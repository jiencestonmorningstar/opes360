<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Admin\AdminResources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The generic browser over every tenant-owned table.
 *
 * One controller for all of them, driven by AdminResources — see the note
 * there on why this is a registry rather than twenty bespoke screens.
 *
 * Read only. Nothing here writes.
 */
class ResourceController extends Controller
{
    public function index(Request $request, string $resource)
    {
        $definition = AdminResources::find($resource);

        abort_if($definition === null, 404);

        $company = $this->company($request);

        [$query, $search] = $this->filtered($request, $definition, $company);

        return view('admin.resources.index', [
            'key' => $resource,
            'definition' => $definition,
            'company' => $company,
            'search' => $search,
            'rows' => $query->paginate(50)->withQueryString(),
            'resources' => AdminResources::all(),
        ]);
    }

    /**
     * The same list as a file. An admin asked to produce a business's records —
     * for a dispute, or a tax query the business itself cannot answer — needs
     * to hand over something, and reading 4 000 rows off a screen is not it.
     */
    public function export(Request $request, string $resource): StreamedResponse
    {
        $definition = AdminResources::find($resource);

        abort_if($definition === null, 404);

        $company = $this->company($request);

        [$query] = $this->filtered($request, $definition, $company);

        $headers = array_keys($definition['columns']);
        $filename = trim(($company?->slug ? $company->slug.'-' : '').$resource).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $definition, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);

            // Chunked: an export is the one place in the admin panel likely to
            // meet a table with six figures of rows in it.
            $query->chunk(500, function ($rows) use ($out, $definition) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(
                        fn (callable $render) => (string) $render($row),
                        array_values($definition['columns']),
                    ));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    protected function company(Request $request): ?Company
    {
        $slug = $request->query('company');

        return $slug ? Company::withTrashed()->where('slug', $slug)->firstOrFail() : null;
    }

    /** @return array{0: Builder, 1: string} */
    protected function filtered(Request $request, array $definition, ?Company $company): array
    {
        $query = AdminResources::query($definition, $company);
        $search = trim((string) $request->query('q', ''));

        if ($search !== '' && ($definition['search'] ?? [])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

            $query->where(function ($q) use ($definition, $like) {
                foreach ($definition['search'] as $field) {
                    $q->orWhere($field, 'like', $like);
                }
            });
        }

        return [$query->latest(), $search];
    }
}
