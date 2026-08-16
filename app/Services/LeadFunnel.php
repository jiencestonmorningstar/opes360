<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Leads and what becomes of them.
 *
 * The rules live here rather than in the screen for the same reason
 * DealPipeline exists next door: conversion touches three tables, and two
 * places deciding what "converted" means is how a lead ends up marked
 * converted with no contact to show for it.
 *
 * Terminal states are deliberate acts. A plain moveTo() can walk the funnel
 * (new → working → qualified) but cannot reach `converted` or `lost` — those
 * go through convert() and lose(), which are the only places the links and
 * the reason are written.
 */
class LeadFunnel
{
    /**
     * @param  array{name: string, company_name?: ?string, phone?: ?string, email?: ?string,
     *               source?: ?string, notes?: ?string, assigned_to?: ?int}  $data
     */
    public function create(array $data, ?User $actor = null): Lead
    {
        if (app(CurrentCompany::class)->get() === null) {
            throw new RuntimeException('Cannot create a lead without a current company.');
        }

        return Lead::create([
            'name' => $data['name'],
            'company_name' => $data['company_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'source' => $data['source'] ?? null,
            'notes' => $data['notes'] ?? null,
            // Explicit, because Model::create() does not read DB defaults back.
            'status' => 'new',
            'assigned_to' => $data['assigned_to'] ?? $actor?->id,
        ]);
    }

    /** Walk the funnel. Terminal states are refused: they carry side effects. */
    public function moveTo(Lead $lead, string $status): Lead
    {
        if (! array_key_exists($status, Lead::STATUSES)) {
            throw new InvalidArgumentException("Unknown lead status [{$status}].");
        }

        if (in_array($status, Lead::CLOSED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Converted and lost are reached through convert() and lose(), not a plain move.'
            );
        }

        $lead->forceFill(['status' => $status])->save();

        return $lead;
    }

    /**
     * The lead earns a place in the customer book — and, if asked, a card on
     * the pipeline board.
     *
     * Everything goes through the EXISTING models: a Contact like any other,
     * a Deal through DealPipeline so the board's rules apply from birth. The
     * lead row survives with the links, so "where did this customer come from"
     * stays answerable.
     *
     * @param  array{title?: string, value?: float|string, expected_close_on?: ?string}  $deal
     */
    public function convert(Lead $lead, User $actor, array $deal = []): Contact
    {
        if (! $lead->isOpen()) {
            throw new RuntimeException('This lead has already been closed — it cannot be converted.');
        }

        return DB::transaction(function () use ($lead, $actor, $deal) {
            $contact = Contact::create([
                'type' => 'customer',
                'name' => $lead->name,
                'company_name' => $lead->company_name,
                'phones' => $lead->phone !== null ? [$lead->phone] : null,
                'email' => $lead->email,
                'created_by' => $actor->id,
            ]);

            $dealId = null;

            if ($deal !== []) {
                $dealId = app(DealPipeline::class)->create([
                    'title' => $deal['title'] ?? $lead->name,
                    'contact_id' => $contact->id,
                    'value' => $deal['value'] ?? 0,
                    'expected_close_on' => $deal['expected_close_on'] ?? null,
                    'owner_id' => $lead->assigned_to ?? $actor->id,
                ], $actor)->id;
            }

            $lead->forceFill([
                'status' => 'converted',
                'contact_id' => $contact->id,
                'deal_id' => $dealId,
                'converted_at' => now(),
            ])->save();

            return $contact;
        });
    }

    /**
     * The lead goes nowhere, and the reason is written down — the only data a
     * dead lead produces, and the input to "which sources are worth the time".
     */
    public function lose(Lead $lead, string $reason): Lead
    {
        if (! $lead->isOpen()) {
            throw new RuntimeException('This lead has already been closed.');
        }

        $lead->forceFill([
            'status' => 'lost',
            'lost_reason' => $reason,
        ])->save();

        return $lead;
    }
}
