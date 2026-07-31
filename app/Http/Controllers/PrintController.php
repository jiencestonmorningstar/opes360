<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\VerificationToken;
use Carbon\CarbonImmutable;
use App\Services\DocumentComposer;
use App\Services\LogoComposer;
use App\Services\QrCodes;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
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

    /**
     * A customer's statement of account: what was billed, what was paid, and
     * the running balance over a period. A report, not a numbered document —
     * regenerating it always reflects the ledger as it stands, which is
     * exactly what the customer asking "what do I owe you" wants.
     */
    public function statement(Request $request, Contact $contact)
    {
        $from = CarbonImmutable::make($request->query('from')) ?? now()->startOfYear()->toImmutable();
        $to = (CarbonImmutable::make($request->query('to')) ?? now()->toImmutable())->endOfDay();

        // Charges: issued receivable documents. Credits: payments received and
        // credit notes. Everything else (quotations, proformas, delivery
        // notes) informs no balance and has no place on a statement.
        $documents = $contact->documents()
            ->issued()
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('type', [DocumentType::Invoice, DocumentType::DebitNote, DocumentType::CreditNote])
            ->get(['id', 'type', 'number', 'issue_date', 'total']);

        // Voided payments are soft-deleted, so the default scope already
        // keeps them off the statement.
        $payments = $contact->payments()
            ->whereBetween('received_at', [$from, $to])
            ->get(['id', 'reference', 'received_at', 'amount']);

        $lines = collect()
            ->concat($documents->map(fn (Document $document) => [
                'date' => $document->issue_date,
                'reference' => $document->number,
                'description' => $document->type->label(),
                'debit' => $document->type === DocumentType::CreditNote ? 0.0 : (float) $document->total,
                'credit' => $document->type === DocumentType::CreditNote ? (float) $document->total : 0.0,
            ]))
            ->concat($payments->map(fn (Payment $payment) => [
                'date' => $payment->received_at,
                'reference' => $payment->reference ?? '—',
                'description' => 'Payment received',
                'debit' => 0.0,
                'credit' => (float) $payment->amount,
            ]))
            ->sortBy('date')
            ->values();

        $running = 0.0;
        $lines = $lines->map(function (array $line) use (&$running) {
            $running += $line['debit'] - $line['credit'];
            $line['balance'] = $running;

            return $line;
        });

        return view('print.statement', [
            'contact' => $contact,
            'company' => app(CurrentCompany::class)->get(),
            'from' => $from,
            'to' => $to,
            'lines' => $lines,
            'totalDebits' => $lines->sum('debit'),
            'totalCredits' => $lines->sum('credit'),
            'closing' => $running,
            'autoprint' => $request->boolean('print'),
        ]);
    }

    /**
     * A generated business document, on the company letterhead.
     *
     * Deliberately the same sheet as the stationery: a contract that does not
     * look like it came from the same business as the invoice invites the
     * question of whether it did.
     */
    public function paper(Request $request, BusinessDocument $paper, QrCodes $qr, DocumentComposer $composer)
    {
        $paper->load('verificationToken');

        return view('print.paper', [
            'paper' => $paper,
            'company' => app(CurrentCompany::class)->get(),
            'bodyHtml' => $composer->toHtml($paper->body),
            'notice' => ($paper->template()['binding'] ?? false)
                ? DocumentTemplates::reviewNotice()
                : null,
            'qrSvg' => $paper->verificationToken
                ? $qr->svg($paper->verificationToken->publicUrl(), 120)
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
