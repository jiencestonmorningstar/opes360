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
use App\Models\Shipment;
use App\Models\TripManifest;
use App\Models\VerificationToken;
use App\Models\VipMembership;
use App\Services\DocumentComposer;
use App\Services\LogoComposer;
use App\Services\LoyaltyLedger;
use App\Services\QrCodes;
use App\Support\Audit;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use App\Support\Pdf;
use App\Support\Watermarks;
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
 *
 * Phase 5: the same endpoints answer ?format=pdf with a real file, rendered
 * from the same view data through App\Support\Pdf — one source of truth for
 * what a document says, two ways of putting it on paper.
 */
class PrintController extends Controller
{
    /** Whether this request wants a downloadable PDF instead of the print page. */
    protected function wantsPdf(Request $request): bool
    {
        return $request->query('format') === 'pdf';
    }

    public function document(Request $request, Document $document, QrCodes $qr, Pdf $pdf)
    {
        $document->load(['contact', 'lines', 'verificationToken']);
        $company = app(CurrentCompany::class)->get();

        $data = [
            'document' => $document,
            'company' => $company,
            'qrSvg' => $document->verificationToken
                ? $qr->svg($document->verificationToken->publicUrl(), 132, brand: $company)
                : null,
            'autoprint' => $request->boolean('print'),
        ];

        if ($this->wantsPdf($request)) {
            return $pdf->download(
                'print.document',
                array_merge($data, ['autoprint' => false]),
                Pdf::filename($document->number ?? 'draft', $document->type->label()),
            );
        }

        return view('print.document', $data);
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

        $data = [
            'contact' => $contact,
            'company' => $company,
            'from' => $from,
            'to' => $to,
            'lines' => $lines,
            'totalDebits' => $lines->sum('debit'),
            'totalCredits' => $lines->sum('credit'),
            'closing' => $running,
            'qrSvg' => $qr->svg($token->publicUrl(), 110, brand: $company),
            'autoprint' => $request->boolean('print'),
        ];

        if ($this->wantsPdf($request)) {
            return app(Pdf::class)->download(
                'print.statement',
                array_merge($data, ['autoprint' => false]),
                Pdf::filename('Statement', $contact->displayName(), $from->format('Y-m-d'), $to->format('Y-m-d')),
            );
        }

        return view('print.statement', $data);
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

        $company = app(CurrentCompany::class)->get();

        /*
         * §2.17: opening the print view of a confidential or restricted paper
         * is data leaving the system — the next click is the print dialog and
         * a copy loose in the world. Export-tier on purpose (Audit::record,
         * like Audit::exported — never the windowed accessed()): two prints
         * are two copies, and collapsing them would hide the one that matters.
         */
        if ($paper->isConfidential()) {
            Audit::record($paper, 'exported', [
                // A PDF download is exactly as much a copy in the world as a
                // print, so the same export-tier row fires for both — only
                // the named channel differs.
                'export' => $this->wantsPdf($request) ? 'pdf' : 'print',
                'security' => $paper->security,
            ]);
        }

        $data = [
            'watermark' => Watermarks::statusMark($paper),
            'confidentialFooter' => Watermarks::confidentialFooter(
                $paper,
                $company,
                $request->user()?->name ?? 'Unknown viewer',
            ),
            'paper' => $paper,
            'company' => app(CurrentCompany::class)->get(),
            'bodyHtml' => $composer->toHtml($paper->body),
            'notice' => ($paper->template()['binding'] ?? false)
                ? DocumentTemplates::reviewNotice()
                : null,
            'qrSvg' => $paper->verificationToken
                ? $qr->svg($paper->verificationToken->publicUrl(), 120, brand: $company)
                : null,
            'autoprint' => $request->boolean('print'),
        ];

        if ($this->wantsPdf($request)) {
            return app(Pdf::class)->download(
                'print.paper',
                array_merge($data, ['autoprint' => false]),
                Pdf::filename($paper->reference, $paper->title),
            );
        }

        return view('print.paper', $data);
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
            'qrSvg' => $qr->svg($contact->loyaltyVerificationToken->publicUrl(), 110, brand: $company),
        ]);
    }

    /**
     * A member's card.
     *
     * The QR resolves to the same public verification page every other printed
     * artefact uses, so somebody on the door can check a card against the
     * business without an account and without ringing the office.
     */
    public function vipCard(VipMembership $membership, QrCodes $qr)
    {
        $company = app(CurrentCompany::class)->get();
        abort_if($company === null, 404);

        $membership->loadMissing('contact', 'verificationToken');

        return view('print.vip-card', [
            'company' => $company,
            'membership' => $membership,
            // Older memberships predate card issuing, so this is not assumed.
            'qrSvg' => $membership->verificationToken
                ? $qr->svg($membership->verificationToken->publicUrl(), 110, brand: $company)
                : null,
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
                brand: $company,
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
                brand: $subject,
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
        $company = app(CurrentCompany::class)->get();

        return view('print.receipt', [
            'receipt' => $receipt,
            'company' => $company,
            'qrSvg' => $receipt->verificationToken
                ? $qr->svg($receipt->verificationToken->publicUrl(), 120, brand: $company)
                : null,
            'autoprint' => $request->boolean('print'),
        ]);
    }

    /**
     * The waybill — the consignment note that travels with the cargo.
     *
     * The paper the driver hands over: sender, receiver, cargo, declared
     * value, and the tracking QR, which points at the shipment's own public
     * tracking page — the same link the office quotes, so the paper and the
     * website can never tell different stories. Deliberately NO freight
     * amount: the waybill travels with the goods through third hands, and
     * the money lives on the invoice, which is where money lives.
     *
     * Watermarked by the Watermarks doctrine: a cancelled shipment's waybill
     * that printed clean would be live cargo paperwork again.
     */
    public function waybill(Request $request, Shipment $shipment, QrCodes $qr, Pdf $pdf)
    {
        $shipment->load(['sender', 'receiver', 'events']);
        $company = app(CurrentCompany::class)->get();

        $data = [
            'shipment' => $shipment,
            'company' => $company,
            'watermark' => match ($shipment->status) {
                'cancelled' => 'CANCELLED',
                'returned' => 'RETURNED',
                default => null,
            },
            'qrSvg' => $qr->svg($shipment->trackingUrl(), 120, brand: $company),
            'autoprint' => $request->boolean('print'),
        ];

        if ($this->wantsPdf($request)) {
            return $pdf->download(
                'print.waybill',
                array_merge($data, ['autoprint' => false]),
                Pdf::filename('Waybill', $shipment->reference),
            );
        }

        return view('print.waybill', $data);
    }

    /**
     * The driver's loading sheet: what is aboard, weights, and the stops in
     * order. An internal working paper — it names every consignment on the
     * van, so unlike the waybill it must never leave the company's hands,
     * and it prints no tracking links and no money.
     */
    public function manifest(Request $request, TripManifest $manifest, Pdf $pdf)
    {
        $manifest->load(['vehicle.vehicle', 'driver', 'shipments.sender', 'shipments.receiver']);

        $shipments = $manifest->shipments
            ->reject(fn (Shipment $s) => $s->status === 'cancelled')
            ->values();

        $data = [
            'manifest' => $manifest,
            'shipments' => $shipments,
            'totalWeight' => $shipments->sum(fn (Shipment $s) => (float) ($s->weight_kg ?? 0)),
            // The stops, in the order the destinations first appear.
            'stops' => $shipments->pluck('to_location')->unique()->values(),
            'company' => app(CurrentCompany::class)->get(),
            'watermark' => $manifest->isOpen() ? 'DRAFT' : null,
            'autoprint' => $request->boolean('print'),
        ];

        if ($this->wantsPdf($request)) {
            return $pdf->download(
                'print.manifest',
                array_merge($data, ['autoprint' => false]),
                Pdf::filename('Manifest', $manifest->reference),
            );
        }

        return view('print.manifest', $data);
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

        $data = [
            'payslip' => $payslip,
            'company' => app(CurrentCompany::class)->get(),
            'autoprint' => $request->boolean('print'),
        ];

        if ($this->wantsPdf($request)) {
            return app(Pdf::class)->download(
                'print.payslip',
                array_merge($data, ['autoprint' => false]),
                Pdf::filename('Bulletin', $payslip->employeeName(), $payslip->run?->period?->format('Y-m')),
            );
        }

        return view('print.payslip', $data);
    }
}
