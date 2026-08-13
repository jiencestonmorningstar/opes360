<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\WebhookEvents;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creating deals and moving them through the board.
 *
 * The rules live here rather than in the screen because the API, the Livewire
 * board and any future import all have to agree about what winning a deal
 * means. A stage change written straight to the model from two places is how
 * `closed_at` ends up set on half the won deals and null on the rest.
 */
class DealPipeline
{
    /**
     * @param  array{title: string, contact_id?: ?string, lead_name?: ?string, lead_phone?: ?string,
     *               value?: float|string|null, currency?: ?string, stage?: ?string,
     *               expected_close_on?: ?string, notes?: ?string, owner_id?: ?int}  $data
     */
    public function create(array $data, ?User $actor = null): Deal
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot create a deal without a current company.');
        }

        $stage = $data['stage'] ?? 'lead';
        $this->assertStageExists($stage);

        // A deal has to be about somebody, but that somebody is often just a
        // name and a number before they are a customer record.
        if (empty($data['contact_id']) && empty($data['lead_name'])) {
            throw new InvalidArgumentException('A deal needs either a contact or a lead name.');
        }

        if (! empty($data['contact_id'])) {
            // Resolved through the tenant scope, so a contact id belonging to
            // another company simply does not exist here.
            Contact::findOrFail($data['contact_id']);
        }

        return DB::transaction(function () use ($data, $stage, $actor) {
            $deal = new Deal([
                'title' => $data['title'],
                'contact_id' => $data['contact_id'] ?? null,
                'lead_name' => $data['lead_name'] ?? null,
                'lead_phone' => $data['lead_phone'] ?? null,
                'notes' => $data['notes'] ?? null,
                'value' => $data['value'] ?? 0,
                'currency' => $data['currency'] ?? 'XAF',
                'stage' => $stage,
                'expected_close_on' => $data['expected_close_on'] ?? null,
                'owner_id' => $data['owner_id'] ?? $actor?->id,
            ]);

            if (in_array($stage, Deal::CLOSED_STAGES, true)) {
                $deal->closed_at = now();
            }

            $deal->save();

            return $deal;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Deal $deal, array $data): Deal
    {
        if (array_key_exists('stage', $data) && $data['stage'] !== null) {
            $this->assertStageExists($data['stage']);
        }

        return DB::transaction(function () use ($deal, $data) {
            $deal->fill(array_intersect_key($data, array_flip([
                'title', 'contact_id', 'lead_name', 'lead_phone', 'notes',
                'value', 'currency', 'expected_close_on', 'owner_id',
            ])));

            if (array_key_exists('stage', $data) && $data['stage'] !== null) {
                $this->applyStage($deal, $data['stage'], $data['lost_reason'] ?? null);
            }

            $deal->save();

            return $deal;
        });
    }

    /**
     * Move a deal to a stage. The one place `closed_at` and `lost_reason` are
     * kept honest against the stage they describe.
     */
    public function moveTo(Deal $deal, string $stage, ?string $lostReason = null): Deal
    {
        $this->assertStageExists($stage);

        return DB::transaction(function () use ($deal, $stage, $lostReason) {
            $this->applyStage($deal, $stage, $lostReason);
            $deal->save();

            return $deal;
        });
    }

    /**
     * Turn a won deal into a draft invoice.
     *
     * Draft, deliberately — not issued. Issuing is the moment a document
     * becomes immutable and enters the books, and nothing here knows the line
     * items, the quantities or the tax treatment: it has one figure somebody
     * typed into a pipeline card. So this gets the invoice started with the
     * customer, the amount and the description already filled in, and a person
     * still checks it before it becomes paper.
     *
     * A deal that was only ever a lead becomes a customer at this point, which
     * is the natural moment for it: the business is about to invoice them.
     */
    public function convertToInvoice(Deal $deal, User $actor): Document
    {
        if ($deal->document_id !== null) {
            throw new RuntimeException('This deal already has an invoice.');
        }

        if ($deal->stage !== 'won') {
            throw new RuntimeException('Only a won deal can be invoiced.');
        }

        return DB::transaction(function () use ($deal, $actor) {
            $contact = $deal->contact ?? Contact::create([
                'type' => 'customer',
                'name' => $deal->lead_name,
                'phones' => $deal->lead_phone !== null ? [$deal->lead_phone] : null,
                'created_by' => $actor->id,
            ]);

            $document = Document::create([
                'type' => DocumentType::Invoice,
                'contact_id' => $contact->id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays($contact->payment_terms_days ?? 14)->toDateString(),
                'currency' => $deal->currency,
                'subtotal' => $deal->value,
                'total' => $deal->value,
                'balance' => $deal->value,
                'notes' => $deal->notes,
                'created_by' => $actor->id,
            ]);

            DocumentLine::create([
                'document_id' => $document->id,
                'description' => $deal->title,
                'quantity' => 1,
                'unit_price' => $deal->value,
                'line_total' => $deal->value,
            ]);

            // Both directions: the deal remembers what it became, and if the
            // deal had no contact it now has one.
            $deal->forceFill([
                'document_id' => $document->id,
                'contact_id' => $contact->id,
            ])->save();

            return $document;
        });
    }

    private function applyStage(Deal $deal, string $stage, ?string $lostReason): void
    {
        $closing = in_array($stage, Deal::CLOSED_STAGES, true);

        /*
         * Winning is announced from here, the one place a stage change is
         * applied — the same reason `closed_at` is set here rather than at the
         * three call sites. `wasWon` guards against announcing it twice: a
         * board that saves a won deal again, or an update that touches the
         * title while leaving the stage alone, is not a second win.
         */
        $wasWon = $deal->stage === 'won';

        $deal->stage = $stage;

        // Reopening a closed deal has to clear the closure, or the deal keeps
        // claiming it was settled on a date it is plainly still open past.
        $deal->closed_at = $closing ? ($deal->closed_at ?? now()) : null;
        $deal->lost_reason = $stage === 'lost' ? $lostReason : null;

        if ($stage === 'won' && ! $wasWon) {
            app(WebhookDispatcher::class)->send(WebhookEvents::DEAL_WON, [
                'id' => $deal->id,
                'title' => $deal->title,
                'contact_id' => $deal->contact_id,
                'lead_name' => $deal->lead_name,
                'value' => (float) $deal->value,
                'currency' => $deal->currency,
                'owner_id' => $deal->owner_id,
                'won_at' => $deal->closed_at?->toIso8601String(),
            ]);
        }
    }

    private function assertStageExists(string $stage): void
    {
        if (! array_key_exists($stage, Deal::STAGES)) {
            throw new InvalidArgumentException("Unknown deal stage [{$stage}].");
        }
    }
}
