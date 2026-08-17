<?php

namespace App\Jobs;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentBundle;
use App\Models\Company;
use App\Models\User;
use App\Notifications\DocumentBundleReadyNotification;
use App\Services\Documents\DocumentBundles;
use App\Support\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * §60 — builds a large ZIP off the request/response cycle.
 *
 * Only ever dispatched by DocumentBundles::request() when the queue is not
 * `sync` and the selection cleared the "large" threshold — see that method
 * for the argument. This job carries ids, not models, for the same reason
 * DeliverWebhook does: it may sit on the queue for a while, and a document's
 * permissions or existence can change underneath it before it runs.
 */
class BuildDocumentBundle implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** @param  array<int, string>  $documentIds */
    public function __construct(
        public string $bundleId,
        public string $companyId,
        public array $documentIds,
    ) {}

    public function handle(DocumentBundles $bundles): void
    {
        $bundle = BusinessDocumentBundle::query()->acrossAllCompanies()->find($this->bundleId);

        if ($bundle === null) {
            return;
        }

        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            $bundle->forceFill(['status' => 'failed', 'error' => 'The company no longer exists.'])->save();

            return;
        }

        try {
            app(CurrentCompany::class)->as($company, function () use ($bundle, $bundles) {
                $documents = BusinessDocument::query()->whereIn('id', $this->documentIds)->get();

                $tempPath = $bundles->zipDocuments($documents);

                $storedPath = 'bundles/'.$bundle->company_id.'/'.$bundle->id.'.zip';
                Storage::disk('documents')->put($storedPath, (string) file_get_contents($tempPath));
                @unlink($tempPath);

                $bundle->forceFill([
                    'status' => 'ready',
                    'disk' => 'documents',
                    'path' => $storedPath,
                    'filename' => 'documents-'.Str::lower(Str::random(6)).'.zip',
                    'document_count' => $documents->count(),
                    'ready_at' => now(),
                ])->save();
            });
        } catch (Throwable $e) {
            report($e);

            $bundle->forceFill([
                'status' => 'failed',
                'error' => Str::limit($e->getMessage(), 490),
            ])->save();

            return;
        }

        $creator = $bundle->created_by ? User::query()->find($bundle->created_by) : null;

        if ($creator !== null) {
            try {
                $creator->notify(new DocumentBundleReadyNotification($bundle));
            } catch (Throwable $e) {
                // The bundle is built and stored either way; a notification
                // failure must not make a ready download look failed.
                report($e);
                Log::warning('BuildDocumentBundle: the bundle is ready but the notification failed to send.', [
                    'bundle' => $bundle->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
