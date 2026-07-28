<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Document;
use App\Models\Receipt;
use App\Models\VerificationToken;
use App\Services\LogoComposer;
use App\Services\QrCodes;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Print-ready views — the browser-print path from the architecture plan.
 *
 * The same templates render in the user's own browser and go to PDF or paper
 * through the native print dialog, which is what keeps printing available
 * offline. Server-side Chromium rendering reuses these templates in Phase 3.
 */
class PrintController extends Controller
{
    public function document(Request $request, Document $document, QrCodes $qr)
    {
        $document->load(['contact', 'lines', 'verificationToken']);

        return view('print.document', [
            'document' => $document,
            'company' => app(CurrentCompany::class)->get(),
            'qrSvg' => $document->verificationToken
                ? $qr->svg($document->verificationToken->publicUrl(), 132)
                : null,
            'autoprint' => $request->boolean('print'),
        ]);
    }

    public function stationery(Request $request, QrCodes $qr)
    {
        $company = app(CurrentCompany::class)->get();
        abort_if($company === null, 404);

        $token = VerificationToken::firstOrCreate(
            ['subject_type' => Company::class, 'subject_id' => $company->id],
            ['token' => VerificationToken::newToken(), 'company_id' => $company->id],
        );

        $asset = $request->string('asset')->toString();

        return view('print.stationery', [
            'company' => $company,
            'asset' => in_array($asset, ['letterhead', 'card', 'stamp'], true) ? $asset : 'letterhead',
            'size' => $request->string('size')->toString() === 'a3' ? 'a3' : 'a4',
            'shape' => in_array($request->string('shape')->toString(), ['circular', 'square', 'oval'], true)
                ? $request->string('shape')->toString()
                : 'circular',
            'name' => $request->string('name')->toString() ?: $request->user()->name,
            'title' => $request->string('title')->toString() ?: 'Business Owner',
            // Sized per asset: a card QR is physically small, a letterhead's larger.
            'qrSvg' => $qr->svg($token->publicUrl(), $asset === 'letterhead' ? 150 : 110),
        ]);
    }

    /** Downloads the current logo configuration as a standalone SVG file. */
    public function logo(Request $request, LogoComposer $composer)
    {
        $company = app(CurrentCompany::class)->get();
        abort_if($company === null, 404);

        $svg = $composer->render(
            $company->name,
            $request->string('tagline')->toString(),
            [
                'palette' => $request->string('palette')->toString(),
                'mark' => $request->string('mark')->toString(),
                'layout' => $request->string('layout')->toString(),
            ],
        );

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$company->slug.'-logo.svg"',
        ]);
    }

    public function receipt(Request $request, Receipt $receipt, QrCodes $qr)
    {
        $receipt->load(['contact', 'payment', 'cashier', 'verificationToken']);

        return view('print.receipt', [
            'receipt' => $receipt,
            'company' => app(CurrentCompany::class)->get(),
            'qrSvg' => $receipt->verificationToken
                ? $qr->svg($receipt->verificationToken->publicUrl(), 120)
                : null,
            'autoprint' => $request->boolean('print'),
        ]);
    }
}
