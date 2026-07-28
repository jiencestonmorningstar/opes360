<?php

namespace App\Support;

/**
 * The business-document template library (Module 13).
 *
 * These are drafting aids, not legal advice. Every template that creates an
 * obligation carries a review notice into the rendered output, because the
 * failure mode here is a business signing something it never read on the
 * strength of the software having produced it. That notice is deliberately part
 * of the document rather than a dismissible dialog.
 *
 * Body syntax is intentionally tiny — `## heading`, `- bullet`, blank-line
 * paragraphs, and `{{ placeholder }}` — so the composer can render it to both
 * screen and print without a markdown dependency, and so a business owner
 * editing a template is not confronted with HTML.
 */
class DocumentTemplates
{
    /**
     * Placeholders every template gets for free, filled from the company and
     * the clock rather than asked for.
     */
    public const AUTOMATIC = ['company.name', 'company.address', 'company.email', 'company.phone', 'today'];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'service_agreement' => [
                'name' => 'Service Agreement',
                'summary' => 'Scope, fees and terms between you and a client.',
                'icon' => 'document',
                'accent' => 'blue',
                'binding' => true,
                'fields' => [
                    ['key' => 'client_name', 'label' => 'Client name', 'required' => true],
                    ['key' => 'services', 'label' => 'Services to be provided', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'Describe what you will deliver.'],
                    ['key' => 'fee', 'label' => 'Fee', 'required' => true, 'placeholder' => 'e.g. $2,500 per month'],
                    ['key' => 'payment_terms', 'label' => 'Payment terms', 'default' => '14 days from invoice date'],
                    ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
                    // Lower-case: this default lands mid-sentence in the body.
                    ['key' => 'duration', 'label' => 'Duration', 'default' => 'until terminated by either party'],
                    ['key' => 'notice_period', 'label' => 'Notice period', 'default' => '30 days'],
                ],
                'body' => <<<'TXT'
                ## 1. Parties

                This agreement is made on {{ today }} between **{{ company.name }}**[ of {{ company.address }}] ("the Provider") and **{{ client_name }}** ("the Client").

                ## 2. Services

                The Provider agrees to supply the following services:

                {{ services }}

                ## 3. Fees and payment

                The Client shall pay {{ fee }}. Invoices are payable within {{ payment_terms }}.

                Amounts unpaid after the due date may be suspended from further service until settled.

                ## 4. Term

                This agreement begins on {{ start_date }} and continues {{ duration }}. Either party may end it by giving {{ notice_period }} written notice.

                ## 5. Ownership

                Work produced under this agreement passes to the Client once it has been paid for in full. Until then it remains the property of the Provider.

                ## 6. Confidentiality

                Each party shall keep confidential any non-public information it learns about the other through this agreement, and shall continue to do so after the agreement ends.

                TXT,
            ],

            'nda' => [
                'name' => 'Non-Disclosure Agreement',
                'summary' => 'Before sharing anything commercially sensitive.',
                'icon' => 'briefcase',
                'accent' => 'purple',
                'binding' => true,
                'fields' => [
                    ['key' => 'other_party', 'label' => 'Other party', 'required' => true],
                    ['key' => 'purpose', 'label' => 'Purpose of disclosure', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'e.g. Evaluating a possible supply arrangement.'],
                    ['key' => 'duration_years', 'label' => 'Confidentiality period (years)', 'default' => '3'],
                ],
                'body' => <<<'TXT'
                ## 1. Parties

                This agreement is made on {{ today }} between **{{ company.name }}** and **{{ other_party }}**.

                ## 2. Purpose

                The parties wish to exchange confidential information for the following purpose:

                {{ purpose }}

                ## 3. Confidential information

                "Confidential information" means any non-public information disclosed by one party to the other, in any form, whether or not marked confidential.

                It does not include information that is already public, was already lawfully known to the receiving party, or is independently developed without reference to what was disclosed.

                ## 4. Obligations

                The receiving party shall:

                - Use the confidential information only for the purpose stated above.
                - Not disclose it to anyone else without written permission.
                - Protect it with at least the care it applies to its own confidential information.

                ## 5. Duration

                These obligations continue for {{ duration_years }} years from the date of this agreement.

                ## 6. Return

                On request, the receiving party shall return or destroy the confidential information and confirm in writing that it has done so.

                TXT,
            ],

            'employment_letter' => [
                'name' => 'Employment Letter',
                'summary' => 'Offer or confirmation of employment.',
                'icon' => 'user',
                'accent' => 'green',
                'binding' => true,
                'fields' => [
                    ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                    ['key' => 'job_title', 'label' => 'Job title', 'required' => true],
                    ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
                    ['key' => 'salary', 'label' => 'Salary', 'required' => true, 'placeholder' => 'e.g. $36,000 per year'],
                    ['key' => 'hours', 'label' => 'Working hours', 'default' => 'Monday to Friday, 9am to 5pm'],
                    ['key' => 'probation', 'label' => 'Probation period', 'default' => '3 months'],
                    ['key' => 'reports_to', 'label' => 'Reports to'],
                ],
                'body' => <<<'TXT'
                Dear {{ employee_name }},

                ## Offer of employment

                We are pleased to offer you the position of **{{ job_title }}** at {{ company.name }}.

                ## Terms

                - **Start date:** {{ start_date }}
                - **Salary:** {{ salary }}
                - **Hours:** {{ hours }}
                - **Probation:** {{ probation }}
                - **Reports to:** {{ reports_to }}

                ## Acceptance

                If these terms are acceptable, please sign below and return a copy to us.

                We look forward to working with you.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                TXT,
            ],

            'business_proposal' => [
                'name' => 'Business Proposal',
                'summary' => 'Pitch a piece of work, with pricing.',
                'icon' => 'trending-up',
                'accent' => 'orange',
                'binding' => false,
                'fields' => [
                    ['key' => 'client_name', 'label' => 'Prepared for', 'required' => true],
                    ['key' => 'objective', 'label' => 'What the client needs', 'type' => 'textarea', 'required' => true],
                    ['key' => 'approach', 'label' => 'How you will do it', 'type' => 'textarea', 'required' => true],
                    ['key' => 'deliverables', 'label' => 'What they receive', 'type' => 'textarea'],
                    ['key' => 'timeline', 'label' => 'Timeline'],
                    ['key' => 'investment', 'label' => 'Price', 'required' => true],
                    ['key' => 'valid_until', 'label' => 'Valid until', 'type' => 'date'],
                ],
                'body' => <<<'TXT'
                Prepared for **{{ client_name }}** · {{ today }}

                ## The objective

                {{ objective }}

                ## Our approach

                {{ approach }}

                ## What you receive

                {{ deliverables }}

                ## Timeline

                {{ timeline }}

                ## Investment

                {{ investment }}

                This proposal is valid until {{ valid_until }}.

                ## Next steps

                If this is the right fit, reply to this proposal and we will send an agreement and a start date. Any part of it can be adjusted before then.

                {{ company.name }}[ · {{ company.email }}][ · {{ company.phone }}]
                TXT,
            ],

            'company_profile' => [
                'name' => 'Company Profile',
                'summary' => 'One-page introduction for tenders and partners.',
                'icon' => 'briefcase',
                'accent' => 'teal',
                'binding' => false,
                'fields' => [
                    ['key' => 'about', 'label' => 'About the business', 'type' => 'textarea', 'required' => true],
                    ['key' => 'services', 'label' => 'What you offer', 'type' => 'textarea', 'required' => true],
                    ['key' => 'clients', 'label' => 'Clients and sectors served', 'type' => 'textarea'],
                    ['key' => 'founded', 'label' => 'Year founded'],
                    ['key' => 'team_size', 'label' => 'Team size'],
                ],
                'body' => <<<'TXT'
                ## About us

                {{ about }}

                ## What we do

                {{ services }}

                ## Who we work with

                {{ clients }}

                ## At a glance

                - **Founded:** {{ founded }}
                - **Team:** {{ team_size }}
                - **Contact:** {{ company.email }}[ · {{ company.phone }}]
                - **Address:** {{ company.address }}
                TXT,
            ],

            'certificate' => [
                'name' => 'Certificate',
                'summary' => 'Recognise completion, service or achievement.',
                'icon' => 'check-circle',
                'accent' => 'pink',
                'binding' => false,
                'fields' => [
                    ['key' => 'recipient', 'label' => 'Awarded to', 'required' => true],
                    ['key' => 'achievement', 'label' => 'For', 'required' => true,
                        'placeholder' => 'e.g. Completing the Level 2 Welding Programme'],
                    ['key' => 'awarded_on', 'label' => 'Date awarded', 'type' => 'date', 'required' => true],
                    ['key' => 'signatory', 'label' => 'Signed by'],
                    ['key' => 'signatory_title', 'label' => 'Signatory title'],
                ],
                'body' => <<<'TXT'
                ## Certificate

                This is to certify that

                # {{ recipient }}

                {{ achievement }}

                Awarded on {{ awarded_on }} by {{ company.name }}.

                {{ signatory }}
                {{ signatory_title }}
                TXT,
            ],

            'meeting_minutes' => [
                'name' => 'Meeting Minutes',
                'summary' => 'A record of what was decided and by whom.',
                'icon' => 'calendar',
                'accent' => 'slate',
                'binding' => false,
                'fields' => [
                    ['key' => 'meeting_title', 'label' => 'Meeting', 'required' => true],
                    ['key' => 'held_on', 'label' => 'Date', 'type' => 'date', 'required' => true],
                    ['key' => 'attendees', 'label' => 'Present', 'type' => 'textarea', 'required' => true],
                    ['key' => 'apologies', 'label' => 'Apologies'],
                    ['key' => 'discussion', 'label' => 'Discussion', 'type' => 'textarea', 'required' => true],
                    ['key' => 'decisions', 'label' => 'Decisions', 'type' => 'textarea'],
                    ['key' => 'actions', 'label' => 'Actions and owners', 'type' => 'textarea'],
                    ['key' => 'next_meeting', 'label' => 'Next meeting'],
                ],
                'body' => <<<'TXT'
                ## {{ meeting_title }}

                Held {{ held_on }} · {{ company.name }}

                ## Present

                {{ attendees }}

                ## Apologies

                {{ apologies }}

                ## Discussion

                {{ discussion }}

                ## Decisions

                {{ decisions }}

                ## Actions

                {{ actions }}

                ## Next meeting

                {{ next_meeting }}
                TXT,
            ],

            'demand_letter' => [
                'name' => 'Payment Demand',
                'summary' => 'A formal, dated request for an overdue amount.',
                'icon' => 'alert',
                'accent' => 'orange',
                'binding' => false,
                'fields' => [
                    ['key' => 'debtor_name', 'label' => 'Owed by', 'required' => true],
                    ['key' => 'invoice_reference', 'label' => 'Invoice number', 'required' => true],
                    ['key' => 'amount', 'label' => 'Amount outstanding', 'required' => true],
                    ['key' => 'original_due_date', 'label' => 'Originally due', 'type' => 'date', 'required' => true],
                    ['key' => 'pay_by', 'label' => 'Pay by', 'type' => 'date', 'required' => true],
                ],
                'body' => <<<'TXT'
                Dear {{ debtor_name }},

                ## Overdue payment: {{ invoice_reference }}

                Our records show that {{ amount }} remains outstanding on invoice {{ invoice_reference }}, which was due on {{ original_due_date }}.

                We would be grateful for payment by **{{ pay_by }}**.

                If payment has been made since this letter was prepared, please accept our thanks and disregard it. If there is a dispute or a difficulty with the amount, contact us before that date and we will work something out.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                {{ company.email }}[ · {{ company.phone }}]
                TXT,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * Shown on every template that creates an obligation.
     *
     * Printed into the document itself rather than shown once at compose time:
     * the person who needs to read it is often not the person who generated it.
     */
    public static function reviewNotice(): string
    {
        return 'This document was generated from a standard template and has not been reviewed by a lawyer. '
            .'Check that it says what you mean before signing it, and take legal advice where the stakes warrant it.';
    }
}
