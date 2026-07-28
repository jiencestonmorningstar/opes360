<?php

namespace App\Http\Controllers;

use App\Models\Artisan;
use App\Models\Company;
use App\Models\Document;
use App\Models\Receipt;
use App\Models\Scopes\CompanyScope;
use App\Models\VerificationToken;
use App\Services\QrCodes;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Public verification pages — the destination behind every printed QR.
 *
 * These routes are the one legitimate public crossing of the tenant boundary:
 * the person scanning is a customer, not a user, so there is no session and no
 * current company. The token itself names the company, and everything is
 * resolved *as* that company — never unscoped across all tenants.
 */
class VerificationController extends Controller
{
    public function show(Request $request, string $token)
    {
        $verification = $this->findToken($token);

        if ($verification === null) {
            // A branded "unknown" page, not a bare 404: the person holding a
            // paper whose code does not resolve deserves an explicit verdict.
            return response()->view('verification.show', [
                'verdict' => 'unknown',
                'subjectKind' => null,
                'company' => null,
                'document' => null,
                'receipt' => null,
            ], 404);
        }

        $company = Company::find($verification->company_id);

        return app(CurrentCompany::class)->as($company, function () use ($request, $verification, $company) {
            $this->recordScan($request, $verification);

            [$verdict, $subjectKind, $document, $receipt] = $this->resolveSubject($verification);

            return response()->view('verification.show', [
                'verdict' => $verdict,
                'subjectKind' => $subjectKind,
                'company' => $company,
                'document' => $document,
                'receipt' => $receipt,
            ]);
        });
    }

    public function qr(string $token, QrCodes $qr): Response
    {
        // The QR encodes the verification URL; render it even for revoked tokens
        // so a printed code and its on-screen twin never disagree.
        if ($this->findToken($token) === null) {
            abort(404);
        }

        return response($qr->svg(url('/v/'.$token)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    protected function findToken(string $token): ?VerificationToken
    {
        return VerificationToken::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('token', $token)
            ->first();
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?Document, 3: ?Receipt}
     *
     * Every subject type resolves to one of four verdicts, so the page never has
     * to know what kind of thing it is verifying.
     */
    protected function resolveSubject(VerificationToken $verification): array
    {
        if ($verification->isRevoked()) {
            return ['voided', null, null, null];
        }

        if ($verification->subject_type === Document::class) {
            $document = Document::with(['contact', 'lines'])->find($verification->subject_id);

            if ($document === null || $document->status->value === 'void') {
                return ['voided', 'document', $document, null];
            }

            return [$document->isTampered() ? 'tampered' : 'valid', 'document', $document, null];
        }

        if ($verification->subject_type === Artisan::class) {
            $artisan = Artisan::find($verification->subject_id);

            if ($artisan === null || ! $artisan->is_published) {
                return ['voided', 'artisan', null, null];
            }

            return ['valid', 'artisan', null, null];
        }

        if ($verification->subject_type === Receipt::class) {
            $receipt = Receipt::with(['contact', 'payment'])->find($verification->subject_id);

            if ($receipt === null || $receipt->status === 'void') {
                return ['voided', 'receipt', null, $receipt];
            }

            return [$receipt->isTampered() ? 'tampered' : 'valid', 'receipt', null, $receipt];
        }

        // A company token verifies the business itself.
        return ['valid', 'company', null, null];
    }

    protected function recordScan(Request $request, VerificationToken $verification): void
    {
        $agent = strtolower((string) $request->userAgent());

        $verification->scans()->create([
            'device_class' => str_contains($agent, 'mobile') ? 'mobile' : 'desktop',
            'referrer' => substr((string) $request->headers->get('referer'), 0, 255) ?: null,
            'scanned_at' => now(),
        ]);
    }
}
