<?php

namespace Tests\Feature\Orders;

use App\Services\Orders\Fulfilment;
use App\Support\Pdf;

/**
 * ?format=pdf on the delivery-note print route: the same view data through
 * App\Support\Pdf, asserted at the html() step per that class's own doctrine
 * (content is checked before the engine, not by parsing PDF internals).
 */
class DeliveryNotePdfTest extends OrdersTestCase
{
    public function test_the_print_route_answers_format_pdf_with_a_file(): void
    {
        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        $note = app(Fulfilment::class)->deliver($order, actor: $this->owner);

        $response = $this->actingAs($this->owner)
            ->get(route('orders.delivery-note', $note).'?format=pdf');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            Pdf::filename($note->number, 'Delivery Note'),
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_composed_html_carries_the_note_and_its_customer(): void
    {
        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        $note = app(Fulfilment::class)->deliver($order, actor: $this->owner);

        $html = app(Pdf::class)->html('print.delivery-note', [
            'note' => $note->fresh()->load(['order.contact', 'lines', 'verificationToken']),
            'company' => $this->company,
            'watermark' => $note->statusMark(),
            'qrSvg' => null,
            'autoprint' => false,
        ]);

        $this->assertStringContainsString($note->number, $html);
        $this->assertStringContainsString('Chantier Mbarga', $html);
    }
}
