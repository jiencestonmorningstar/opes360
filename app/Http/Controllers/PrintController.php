<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Livewire\Business\Stationery;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\PartnerClient;
use App\Models\Payment;
use App\Models\Payslip;
use App\Models\Receipt;
use App\Models\VerificationToken;
use App\Services\DocumentComposer;
use App\Services\LogoComposer;
use App\Services\LoyaltyLedger;
use App\Services\QrCodes;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use BaconQrCode\Common\ErrorCorrectionLevel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

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
    public function statement(Request $request, Contact $contact, QrCodes $qr)
    {
        $company = app(CurrentCompany::class)->get();
        abort_if($company === null, 404);

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

        // A statement is a report, not a numbered document, so its QR verifies
        // the business itself — the same company token the stationery carries,
        // created here on first use.
        $token = VerificationToken::firstOrCreate(
            ['subject_type' => Company::class, 'subject_id' => $company->id],
            ['token' => VerificationToken::newToken(), 'company_id' => $company->id],
        );

        return view('print.statement', [
            'contact' => $contact,
            'company' => $company,
            'from' => $from,
            'to' => $to,
            'lines' => $lines,
            'totalDebits' => $lines->sum('debit'),
            'totalCredits' => $lines->sum('credit'),
            'closing' => $running,
            'qrSvg' => $qr->svg($token->publicUrl(), 110),
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

    /** A customer's physical loyalty card — issued lazily if it doesn't exist yet. */
    public function loyaltyCard(Contact $contact, QrCodes $qr, LoyaltyLedger $loyalty)
    {
        $company = app(CurrentCompany::class)->get();
        abort_if($company === null, 404);

        if (! $contact->hasLoyaltyCard()) {
            $contact = $loyalty->issueCard($contact);
        }

        $contact->loadMissing('loyaltyVerificationToken');

        return view('print.loyalty-card', [
            'company' => $company,
            'contact' => $contact,
            'qrSvg' => $qr->svg($contact->loyaltyVerificationToken->publicUrl(), 110),
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
            // The design lives on the company, not the URL: a saved link keeps
            // producing whatever the business currently has chosen. The picker
            // may ask to render a specific design (?design=…) — that overrides
            // this one request only and never saves anything.
            'cardDesign' => in_array($d = $request->string('design')->toString(), Company::cardDesigns(), true)
                ? $d
                : $company->cardDesign(),
            // Embedded on the stationery page: no print bar, sheet scaled to
            // the frame, optionally a single face for the design tiles.
            'preview' => $request->boolean('preview'),
            'face' => in_array($f = $request->string('face')->toString(), ['front', 'back'], true) ? $f : null,
            // Sized per asset: a card QR is physically small, a letterhead's larger.
            //
            // A business card's QR opens the public business profile — the page
            // with the contact details, catalogue and vCard — because that is
            // what every card's caption promises ("scan to view my business",
            // "scan to save our contact"). The letterhead and stamp keep the
            // verification page: there the QR attests to a document.
            'qrSvg' => $qr->svg(
                $asset === 'card' ? route('profile.business', $company) : $token->publicUrl(),
                $asset === 'letterhead' ? 150 : 110,
                // A card's QR sits on a white chip whose padding already gives
                // the code its quiet zone, so the SVG spends none of its width
                // on one; the letterhead and stamp keep the default.
                margin: $asset === 'card' ? 0 : 2,
                level: $asset === 'card' ? ErrorCorrectionLevel::M() : null,
            ),
        ]);
    }

    /**
     * The same stationery sheet, printed for a secretariat's client.
     *
     * A partner client is not a company and never will be unless they sign up,
     * so there is no record here to hand the template. It gets an unsaved
     * Company built from the client's details instead — the sheet only ever
     * reads attributes off it — which means all ninety-eight designs work for a
     * client exactly as they do for the partner's own business, with no second
     * copy of the templates to keep in step.
     *
     * The QR points at the partner's invite link for that client rather than at
     * a public profile the client does not have. Scanning a card the partner
     * printed is then the shortest possible path from "nice card" to "sign up",
     * which is the whole commercial point of the programme.
     */
    public function partnerCard(Request $request, PartnerClient $client, QrCodes $qr)
    {
        Gate::authorize('partners.issue');

        $partner = app(CurrentCompany::class)->get();
        abort_if($partner === null || $client->company_id !== $partner->id, 404);

        $subject = new Company([
            'name' => $client->name,
            'slug' => 'partner-client',
            'industry' => $client->industry,
            'city' => $client->city,
            'email' => $client->email,
            'phones' => array_values(array_filter([$client->phone])),
            'country' => $partner->country,
            'currency' => $partner->currency,
        ]);

        $asset = $request->string('asset')->toString() === 'letterhead' ? 'letterhead' : 'card';
        $requested = $request->string('design')->toString();

        /*
         * Cards and letterheads have separate design sets, and the sheet reads
         * the letterhead's choice off the company rather than from the request.
         * The stand-in has no stored preference, so the request's choice is
         * written onto it here — without which every client letterhead printed
         * as 'rule' no matter what was picked.
         */
        $design = in_array($requested, Company::cardDesigns(), true) ? $requested : 'classic';

        if ($asset === 'letterhead') {
            $subject->letterhead_design = in_array($requested, Stationery::LETTERHEAD_DESIGNS_KEYS, true)
                ? $requested
                : 'rule';
        }

        return view('print.stationery', [
            'company' => $subject,
            'asset' => $asset,
            'size' => 'a4',
            'shape' => 'circular',
            'name' => $request->string('name')->toString() ?: ($client->contact_name ?: $client->name),
            'title' => $request->string('title')->toString() ?: 'Proprietor',
            'cardDesign' => $design,
            'preview' => $request->boolean('preview'),
            'face' => in_array($f = $request->string('face')->toString(), ['front', 'back'], true) ? $f : null,
            'qrSvg' => $qr->svg(
                $client->inviteUrl(),
                $asset === 'letterhead' ? 150 : 110,
                margin: $asset === 'card' ? 0 : 2,
                level: $asset === 'card' ? ErrorCorrectionLevel::M() : null,
            ),
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

    /**
     * A bulletin de paie.
     *
     * Deliberately printed from the payslip's own stored figures and its
     * stored lines, never recomputed: the employee's copy, the CNPS
     * declaration and this sheet all have to say the same thing years after
     * the rates that produced them have changed.
     */
    public function payslip(Request $request, Payslip $payslip)
    {
        $payslip->load(['lines' => fn ($q) => $q->orderBy('sort_order'), 'run', 'employee']);

        return view('print.payslip', [
            'payslip' => $payslip,
            'company' => app(CurrentCompany::class)->get(),
            'autoprint' => $request->boolean('print'),
        ]);
    }
}
