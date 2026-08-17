<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AbortsForSuspendedCompany;
use App\Models\BusinessDocumentSignature;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Services\DocumentComposer;
use App\Services\Documents\DocumentSignatureRequests;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use App\Support\Pdf;
use App\Support\Watermarks;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * The public signing link — the destination behind every signature request.
 *
 * The person opening it is a signer, not a user: no session, no current
 * company, and the signing token itself names both the company and the one
 * signature row it stands for. Resolved *as* that company, the same pattern
 * VerificationController already uses for the same reason.
 */
class SignatureController extends Controller
{
    use AbortsForSuspendedCompany;

    public function show(Request $request, string $token)
    {
        $signature = $this->findSignature($token);

        if ($signature === null) {
            return response()->view('signatures.show', ['verdict' => 'unknown'], 404);
        }

        $company = $this->companyFor($signature);

        return app(CurrentCompany::class)->as($company, function () use ($request, $signature, $company) {
            /*
             * Phase 5 — a signer who has signed takes a copy away. Only after
             * signing: the download is the record of what was executed, and a
             * pending signer already sees the full text on the page. Rendered
             * inside CurrentCompany::as($company), so the token's own tenant
             * is the only one this PDF can name.
             */
            if ($request->query('format') === 'pdf') {
                abort_unless($signature->isSigned(), 403);

                $document = $signature->document;

                return app(Pdf::class)->download('print.paper', [
                    'watermark' => Watermarks::statusMark($document, isCopy: true),
                    'confidentialFooter' => Watermarks::confidentialFooter(
                        $document,
                        $company,
                        $signature->signer_name,
                    ),
                    'paper' => $document,
                    'company' => $company,
                    'bodyHtml' => app(DocumentComposer::class)->toHtml($document->body),
                    'notice' => ($document->template()['binding'] ?? false)
                        ? DocumentTemplates::reviewNotice()
                        : null,
                    'qrSvg' => null,
                    'autoprint' => false,
                ], Pdf::filename($document->reference, $document->title));
            }

            return response()->view('signatures.show', [
                'verdict' => 'found',
                'signature' => $signature,
                'document' => $signature->document,
                'company' => $company,
                'status' => app(DocumentSignatureRequests::class)->status($signature->document),
            ]);
        });
    }

    public function sign(Request $request, string $token)
    {
        $signature = $this->findSignature($token);

        abort_if($signature === null, 404);

        $data = $request->validate([
            'typed_name' => ['required', 'string', 'max:200'],
        ]);

        $company = $this->companyFor($signature);

        return app(CurrentCompany::class)->as($company, function () use ($signature, $data, $request) {
            // A typed-name confirmation, not a drawn signature: what makes
            // this binding is the emailed link only the named signer holds,
            // the IP address recorded with it, and the timestamp — not the
            // brush strokes. Requiring the typed name to be non-empty is a
            // confirm-you-meant-it step, not the mechanism itself.
            if (trim($data['typed_name']) === '') {
                return back()->withErrors(['typed_name' => 'Type your name to confirm.']);
            }

            try {
                app(DocumentSignatureRequests::class)->sign($signature, $request->ip());
            } catch (RuntimeException $e) {
                return back()->withErrors(['signature' => $e->getMessage()]);
            }

            return redirect()->route('signatures.show', $signature->signing_token)
                ->with('status', 'Signed. Thank you.');
        });
    }

    public function decline(Request $request, string $token)
    {
        $signature = $this->findSignature($token);

        abort_if($signature === null, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $company = $this->companyFor($signature);

        return app(CurrentCompany::class)->as($company, function () use ($signature, $data) {
            try {
                app(DocumentSignatureRequests::class)->decline($signature, $data['reason']);
            } catch (RuntimeException $e) {
                return back()->withErrors(['signature' => $e->getMessage()]);
            }

            return redirect()->route('signatures.show', $signature->signing_token)
                ->with('status', 'Declined.');
        });
    }

    protected function findSignature(string $token): ?BusinessDocumentSignature
    {
        return BusinessDocumentSignature::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('signing_token', $token)
            ->first();
    }

    /**
     * The token's own company, or a 404 when it is gone or suspended.
     *
     * Gone: a hard-deleted company with a live token must read as an unknown
     * link, never a TypeError out of CurrentCompany::as(null). Suspended: a
     * business suspended for abuse must not keep executing legally-binding
     * signatures — unlike verification, nothing here is already in a
     * customer's hands.
     */
    protected function companyFor(BusinessDocumentSignature $signature): Company
    {
        $company = Company::find($signature->company_id);

        abort_if($company === null, 404);

        $this->abortIfSuspended($company);

        return $company;
    }
}
