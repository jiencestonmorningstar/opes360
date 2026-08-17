<?php

namespace Tests\Feature\Documents;

use App\Events\DomainEvent;
use App\Services\DocumentComposer;
use App\Services\Documents\DocumentComments;
use App\Services\Documents\DocumentRetention;
use App\Services\Documents\DocumentSharing;
use App\Services\Documents\DocumentSignatureRequests;
use App\Services\Documents\DocumentVersioner;
use Illuminate\Support\Facades\Event;

/**
 * §54/58/59 — every real Documents lifecycle moment announces itself.
 *
 * Each test drives the one true call site for a moment and asserts the
 * matching document.* event actually went out, rather than merely existing
 * in DomainEvents::CATALOGUE. DomainEventCatalogueParityTest (statically,
 * by grep) and this file (dynamically, by exercising the code) are the two
 * halves of "catalogued means it fires".
 */
class DocumentEventStreamTest extends DocumentsTestCase
{
    public function test_creating_a_document_announces_it(): void
    {
        Event::fake([DomainEvent::class]);

        $document = $this->document();

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.created' && $e->subject->is($document));
    }

    public function test_a_content_save_announces_a_new_version(): void
    {
        $document = $this->document();

        Event::fake([DomainEvent::class]);

        $document->update(['title' => 'A new title']);

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.version.created' && $e->subject->is($document));
    }

    public function test_restoring_a_version_announces_it(): void
    {
        $document = $this->document();
        $document->update(['title' => 'Second title']);
        $first = $document->versions()->orderBy('version_number')->first();

        Event::fake([DomainEvent::class]);

        app(DocumentVersioner::class)->restore($document, $first, $this->owner);

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.version.restored');
    }

    public function test_issuing_a_document_announces_it_published(): void
    {
        $document = $this->document();

        Event::fake([DomainEvent::class]);

        app(DocumentComposer::class)->issue($document, $this->owner);

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.published');
    }

    public function test_voiding_a_document_announces_it(): void
    {
        $document = $this->document();
        app(DocumentComposer::class)->issue($document, $this->owner);

        Event::fake([DomainEvent::class]);

        app(DocumentComposer::class)->void($document, $this->owner, 'Superseded');

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.voided');
    }

    public function test_sharing_a_document_announces_it(): void
    {
        $document = $this->document();

        Event::fake([DomainEvent::class]);

        app(DocumentSharing::class)->create($document, $this->owner);

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.shared');
    }

    public function test_commenting_on_a_document_announces_it(): void
    {
        $document = $this->document();

        Event::fake([DomainEvent::class]);

        app(DocumentComments::class)->post($document, $this->owner, 'A note.');

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.commented');
    }

    public function test_requesting_a_signature_announces_it(): void
    {
        $document = $this->document();

        Event::fake([DomainEvent::class]);

        app(DocumentSignatureRequests::class)->request($document, [
            ['name' => 'A Signer', 'email' => 'signer@example.com'],
        ]);

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.signature.requested');
    }

    public function test_placing_a_legal_hold_announces_it(): void
    {
        $document = $this->document();

        Event::fake([DomainEvent::class]);

        app(DocumentRetention::class)->placeLegalHold($document, 'Litigation', $this->owner);

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.legal_hold.placed');
    }

    public function test_lifting_a_legal_hold_announces_it(): void
    {
        $document = $this->document();
        app(DocumentRetention::class)->placeLegalHold($document, 'Litigation', $this->owner);

        Event::fake([DomainEvent::class]);

        app(DocumentRetention::class)->liftLegalHold($document);

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.legal_hold.lifted');
    }

    /** §2.15's sweep — the one true call site for the transition from "expiring" to "expired". */
    public function test_the_reminder_sweep_announces_a_newly_expired_document(): void
    {
        $document = $this->document(['expires_on' => now()->subDay()]);
        $document->forceFill(['status' => 'issued'])->saveQuietly();

        Event::fake([DomainEvent::class]);

        $this->artisan('opes:remind-actions')->assertSuccessful();

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'document.expired' && (string) $e->subject->getKey() === (string) $document->id);
    }
}
