<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Receipt;
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
