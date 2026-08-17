<?php

namespace App\Http\Controllers\Orders;

use App\Models\DeliveryNote;
use App\Services\QrCodes;
use App\Support\CurrentCompany;
use App\Support\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * The delivery note, print-ready — the same one-pipeline stance as
 * PrintController: a self-contained sheet the browser prints, QR-verified
 * through the existing public verification page, with the status watermark
 * the Watermarks doctrine requires (a voided note must never print clean).
 *
 * A separate controller only because PrintController belongs to the
 * platform; the view follows print.document's conventions exactly.
 */
class DeliveryNotePrintController extends Controller
{
    public function __invoke(Request $request, DeliveryNote $note, QrCodes $qr)
    {
        Gate::authorize('orders.view');

        $note->load(['order.contact', 'lines', 'verificationToken']);
        $company = app(CurrentCompany::class)->get();

        $data = [
            'note' => $note,
            'company' => $company,
            'watermark' => $note->statusMark(),
            'qrSvg' => $note->verificationToken
                ? $qr->svg($note->verificationToken->publicUrl(), 132, brand: $company)
                : null,
            'autoprint' => $request->boolean('print'),
        ];

        // ?format=pdf answers with a real file from the same view data —
        // the PrintController convention, one source of truth on paper.
        if ($request->query('format') === 'pdf') {
            return app(Pdf::class)->download(
                'print.delivery-note',
                array_merge($data, ['autoprint' => false]),
                Pdf::filename($note->number, 'Delivery Note'),
            );
        }

        return view('print.delivery-note', $data);
    }
}
