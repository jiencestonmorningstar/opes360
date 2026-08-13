<?php

namespace App\Support;

/**
 * The moments a business can be told about.
 *
 * Deliberately short, and deliberately made only of things that already exist
 * as a real business event with one code path behind them. The temptation with
 * webhooks is to emit a message for every model that saves, which produces a
 * catalogue nobody can reason about and a stream where `contact.updated` fires
 * because somebody fixed a typo in a phone number. An event earns its place
 * here when a business could plausibly say what it would *do* about it.
 *
 * Each one fires from the service that owns the moment — DocumentIssuer,
 * PaymentRecorder, ExpenseRecorder, DealPipeline — and not from a model
 * observer. A model save is not a business event: an invoice row is written
 * several times on its way to being issued, and only one of those writes is
 * the thing an integration means by "issued".
 *
 * Names are `noun.past-tense-verb` and are part of the wire contract. Renaming
 * one breaks every subscriber silently, so they do not get renamed; a new name
 * is added and the old one keeps firing.
 */
class WebhookEvents
{
    public const DOCUMENT_ISSUED = 'document.issued';

    public const DOCUMENT_VOIDED = 'document.voided';

    public const PAYMENT_RECORDED = 'payment.recorded';

    public const EXPENSE_RECORDED = 'expense.recorded';

    public const DEAL_WON = 'deal.won';

    public const CONTACT_CREATED = 'contact.created';

    /**
     * event => what a person setting this up should understand it to mean.
     *
     * The descriptions are user-facing: they are the labels on the settings
     * screen and the event list in the docs, so they say what happened in the
     * business rather than what happened in the database.
     *
     * @var array<string, string>
     */
    public const CATALOGUE = [
        self::DOCUMENT_ISSUED => 'An invoice, quotation or other document was issued.',
        self::DOCUMENT_VOIDED => 'An issued document was cancelled.',
        self::PAYMENT_RECORDED => 'A customer payment was recorded and its receipt issued.',
        self::EXPENSE_RECORDED => 'A supplier bill or an expense was entered.',
        self::DEAL_WON => 'A deal in the pipeline was moved to won.',
        self::CONTACT_CREATED => 'A customer or supplier was added.',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function exists(string $event): bool
    {
        return array_key_exists($event, self::CATALOGUE);
    }

    public static function describe(string $event): string
    {
        return self::CATALOGUE[$event] ?? $event;
    }
}
