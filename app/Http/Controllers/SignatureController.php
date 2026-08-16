<?php

namespace App\Http\Controllers;

use App\Models\BusinessDocumentSignature;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Services\Documents\DocumentSignatureRequests;
use App\Support\CurrentCompany;
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
    public function show(string $token)
    {
        $signature = $this->findSignature($token);

        if ($signature === null) {
            return response()->view('signatures.show', ['verdict' => 'unknown'], 404);
        }

        $company = Company::find($signature->company_id);

        return app(CurrentCompany::class)->as($company, function () use ($signature, $company) {
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

        $company = Company::find($signature->company_id);

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

        $company = Company::find($signature->company_id);

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
}
