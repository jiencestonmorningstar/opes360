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

            // Key stays 'nda' rather than becoming 'one_way_nda': documents
            // already issued from this template store the key, and renaming
            // it would orphan them. The name and summary carry the
            // clarification instead, now that a mutual sibling exists below.
            'nda' => [
                'name' => 'One-Way NDA',
                'summary' => 'Before sharing anything commercially sensitive with someone.',
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

            'offer_letter' => [
                'name' => 'Offer Letter',
                'summary' => 'Offer a role, salary and start date to a candidate.',
                'icon' => 'user-plus',
                'accent' => 'green',
                'binding' => true,
                'fields' => [
                    ['key' => 'candidate_name', 'label' => 'Candidate name', 'required' => true],
                    ['key' => 'position', 'label' => 'Position offered', 'required' => true],
                    ['key' => 'salary', 'label' => 'Salary', 'required' => true, 'placeholder' => 'e.g. $2,000 per month'],
                    ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
                    ['key' => 'probation_period', 'label' => 'Probation period', 'default' => '3 months'],
                    ['key' => 'reports_to', 'label' => 'Reports to'],
                    ['key' => 'respond_by', 'label' => 'Respond by', 'type' => 'date'],
                ],
                'body' => <<<'TXT'
                Dear {{ candidate_name }},

                ## Offer of employment: {{ position }}

                Following your interview, {{ company.name }} is pleased to offer you the position of **{{ position }}**[, reporting to {{ reports_to }}].

                Your salary will be {{ salary }}. Your employment will begin on {{ start_date }}, with a probation period of {{ probation_period }} during which either side may end the engagement with one week's notice.

                A full contract of employment, setting out working hours, leave and other conditions, will be provided for signature on or before your start date. This offer is conditional on any references and documents we have requested proving satisfactory.

                [Please confirm your acceptance in writing by {{ respond_by }}. ]We look forward to working with you.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                {{ company.email }}[ · {{ company.phone }}]
                TXT,
            ],

            'promotion_letter' => [
                'name' => 'Promotion Letter',
                'summary' => 'Confirm a new role and pay for an existing employee.',
                'icon' => 'trending-up',
                'accent' => 'teal',
                'binding' => false,
                'fields' => [
                    ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                    ['key' => 'current_position', 'label' => 'Current position', 'required' => true],
                    ['key' => 'new_position', 'label' => 'New position', 'required' => true],
                    ['key' => 'new_salary', 'label' => 'New salary'],
                    ['key' => 'effective_date', 'label' => 'Effective from', 'type' => 'date', 'required' => true],
                ],
                'body' => <<<'TXT'
                Dear {{ employee_name }},

                ## Promotion to {{ new_position }}

                In recognition of your performance as {{ current_position }}, we are pleased to promote you to **{{ new_position }}**, effective {{ effective_date }}.

                [Your salary will be revised to {{ new_salary }} from the same date. ]All other terms of your employment remain unchanged.

                Congratulations — this promotion reflects the confidence {{ company.name }} has in you.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                TXT,
            ],

            'salary_review_letter' => [
                'name' => 'Salary Review Letter',
                'summary' => 'Notify an employee of a change in pay.',
                'icon' => 'banknotes',
                'accent' => 'green',
                'binding' => false,
                'fields' => [
                    ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                    ['key' => 'position', 'label' => 'Position', 'required' => true],
                    ['key' => 'new_salary', 'label' => 'New salary', 'required' => true],
                    ['key' => 'effective_date', 'label' => 'Effective from', 'type' => 'date', 'required' => true],
                    ['key' => 'reason', 'label' => 'Reason', 'default' => 'your performance and our annual review'],
                ],
                'body' => <<<'TXT'
                Dear {{ employee_name }},

                ## Salary review

                Following {{ reason }}, your salary as {{ position }} will be revised to **{{ new_salary }}**, effective {{ effective_date }}.

                All other terms of your employment remain unchanged. Thank you for your continued contribution to {{ company.name }}.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                TXT,
            ],

            'warning_letter' => [
                'name' => 'Warning Letter',
                'summary' => 'A formal, dated record of a conduct or performance issue.',
                'icon' => 'alert',
                'accent' => 'orange',
                'binding' => false,
                'fields' => [
                    ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                    ['key' => 'position', 'label' => 'Position', 'required' => true],
                    ['key' => 'incident', 'label' => 'What happened', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'Describe the conduct or performance issue, with dates.'],
                    ['key' => 'expectation', 'label' => 'What must change', 'type' => 'textarea', 'required' => true],
                    ['key' => 'warning_level', 'label' => 'Warning level', 'default' => 'first written warning'],
                ],
                'body' => <<<'TXT'
                Dear {{ employee_name }},

                ## Formal warning

                This letter is a {{ warning_level }} regarding your conduct as {{ position }} at {{ company.name }}.

                ## What happened

                {{ incident }}

                ## What we expect

                {{ expectation }}

                A copy of this letter will be kept on your file. Further issues of this kind may lead to further disciplinary action, up to and including termination of employment. If you believe this warning is unfair, you may respond in writing within five working days.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                TXT,
            ],

            'termination_letter' => [
                'name' => 'Termination Letter',
                'summary' => 'End an employment formally, with dates and final pay.',
                'icon' => 'document',
                'accent' => 'slate',
                'binding' => true,
                'fields' => [
                    ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                    ['key' => 'position', 'label' => 'Position', 'required' => true],
                    ['key' => 'reason', 'label' => 'Reason', 'required' => true, 'placeholder' => 'e.g. redundancy, conduct, end of contract'],
                    ['key' => 'last_working_day', 'label' => 'Last working day', 'type' => 'date', 'required' => true],
                    ['key' => 'final_pay_details', 'label' => 'Final pay details', 'type' => 'textarea',
                        'placeholder' => 'Salary to date, unused leave, any deductions.'],
                ],
                'body' => <<<'TXT'
                Dear {{ employee_name }},

                ## Termination of employment

                We write to confirm that your employment with {{ company.name }} as {{ position }} will end on **{{ last_working_day }}**. The reason for this decision is {{ reason }}.

                [## Final pay

                {{ final_pay_details }}

                ]Please return any company property in your possession on or before your last working day. We will provide a reference on request and thank you for your service.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                TXT,
            ],

            'reference_letter' => [
                'name' => 'Reference Letter',
                'summary' => 'Vouch for a former employee or a business partner.',
                'icon' => 'user',
                'accent' => 'blue',
                'binding' => false,
                'fields' => [
                    ['key' => 'subject_name', 'label' => 'Who it is about', 'required' => true],
                    ['key' => 'relationship', 'label' => 'Relationship', 'required' => true,
                        'placeholder' => 'e.g. employed here as Sales Officer from 2023 to 2026'],
                    ['key' => 'assessment', 'label' => 'Your assessment', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'Work quality, reliability, character.'],
                    ['key' => 'addressee', 'label' => 'Addressed to', 'default' => 'To whom it may concern'],
                ],
                'body' => <<<'TXT'
                {{ addressee }},

                ## Reference for {{ subject_name }}

                This letter confirms that {{ subject_name }} is known to {{ company.name }}: {{ relationship }}.

                {{ assessment }}

                We are happy to answer any further questions about this reference.

                Yours faithfully,

                For and on behalf of {{ company.name }}
                {{ company.email }}[ · {{ company.phone }}]
                TXT,
            ],

            'introduction_letter' => [
                'name' => 'Introduction Letter',
                'summary' => 'Introduce your business to a bank, client or institution.',
                'icon' => 'briefcase',
                'accent' => 'purple',
                'binding' => false,
                'fields' => [
                    ['key' => 'recipient_name', 'label' => 'Addressed to', 'required' => true,
                        'placeholder' => 'e.g. The Branch Manager, First Bank'],
                    ['key' => 'purpose', 'label' => 'Purpose', 'required' => true,
                        'placeholder' => 'e.g. to open a corporate account'],
                    ['key' => 'about_us', 'label' => 'About the business', 'type' => 'textarea', 'required' => true],
                    ['key' => 'representative_name', 'label' => 'Representative being introduced'],
                    ['key' => 'representative_role', 'label' => 'Their role'],
                ],
                'body' => <<<'TXT'
                {{ recipient_name }},

                ## Introduction of {{ company.name }}

                We write to introduce {{ company.name }}[, of {{ company.address }},] {{ purpose }}.

                {{ about_us }}

                [We hereby introduce **{{ representative_name }}**[, our {{ representative_role }},] who is authorised to act on our behalf in this matter.

                ]We would be glad to provide any further information you require.

                Yours faithfully,

                For and on behalf of {{ company.name }}
                {{ company.email }}[ · {{ company.phone }}]
                TXT,
            ],

            'leave_approval' => [
                'name' => 'Leave Approval',
                'summary' => 'Approve leave with dates and return expectations.',
                'icon' => 'calendar',
                'accent' => 'teal',
                'binding' => false,
                'fields' => [
                    ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                    ['key' => 'leave_type', 'label' => 'Type of leave', 'default' => 'annual leave'],
                    ['key' => 'from_date', 'label' => 'From', 'type' => 'date', 'required' => true],
                    ['key' => 'to_date', 'label' => 'To', 'type' => 'date', 'required' => true],
                    ['key' => 'resumption_date', 'label' => 'Resumption date', 'type' => 'date', 'required' => true],
                    ['key' => 'handover_to', 'label' => 'Duties handed over to'],
                ],
                'body' => <<<'TXT'
                Dear {{ employee_name }},

                ## Leave approved

                Your request for {{ leave_type }} has been approved, from **{{ from_date }}** to **{{ to_date }}**. You are expected to resume work on **{{ resumption_date }}**.

                [Your duties will be covered by {{ handover_to }} while you are away; please complete a handover before your leave begins.

                ]Enjoy your time off.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                TXT,
            ],

            'internship_letter' => [
                'name' => 'Internship Acceptance',
                'summary' => 'Accept an intern or industrial-attachment student.',
                'icon' => 'user-plus',
                'accent' => 'pink',
                'binding' => false,
                'fields' => [
                    ['key' => 'intern_name', 'label' => 'Intern name', 'required' => true],
                    ['key' => 'institution', 'label' => 'School / institution'],
                    ['key' => 'department', 'label' => 'Department / area of work', 'required' => true],
                    ['key' => 'from_date', 'label' => 'From', 'type' => 'date', 'required' => true],
                    ['key' => 'to_date', 'label' => 'To', 'type' => 'date', 'required' => true],
                    ['key' => 'stipend', 'label' => 'Stipend', 'default' => 'This position is unpaid'],
                ],
                'body' => <<<'TXT'
                Dear {{ intern_name }},

                ## Internship acceptance

                {{ company.name }} is pleased to accept you for an internship in our {{ department }} area, from **{{ from_date }}** to **{{ to_date }}**.[ This placement is undertaken in connection with {{ institution }}.]

                {{ stipend }}. During the placement you will be expected to observe the company's working hours, conduct standards and confidentiality requirements.

                We look forward to having you with us.

                Yours sincerely,

                For and on behalf of {{ company.name }}
                {{ company.email }}[ · {{ company.phone }}]
                TXT,
            ],

            'payslip' => [
                'name' => 'Payslip',
                'summary' => 'A salary breakdown for one pay period.',
                'icon' => 'banknotes',
                'accent' => 'green',
                'binding' => false,
                'fields' => [
                    ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                    ['key' => 'position', 'label' => 'Position', 'required' => true],
                    ['key' => 'pay_period', 'label' => 'Pay period', 'required' => true, 'placeholder' => 'e.g. July 2026'],
                    ['key' => 'gross_pay', 'label' => 'Gross pay', 'required' => true],
                    ['key' => 'allowances', 'label' => 'Allowances', 'type' => 'textarea',
                        'placeholder' => 'e.g. Transport: $50, Housing: $100'],
                    ['key' => 'deductions', 'label' => 'Deductions', 'type' => 'textarea',
                        'placeholder' => 'e.g. Tax: $80, Pension: $40'],
                    ['key' => 'net_pay', 'label' => 'Net pay', 'required' => true],
                    ['key' => 'payment_method', 'label' => 'Paid by', 'default' => 'bank transfer'],
                ],
                'body' => <<<'TXT'
                ## Payslip — {{ pay_period }}

                **Employee:** {{ employee_name }} · {{ position }}

                **Gross pay:** {{ gross_pay }}

                [## Allowances

                {{ allowances }}

                ][## Deductions

                {{ deductions }}

                ]## Net pay

                **{{ net_pay }}**, paid by {{ payment_method }}.

                This payslip is a record of salary paid by {{ company.name }} for the period shown. Queries should reach {{ company.email }} within 30 days.
                TXT,
            ],

            'payment_voucher' => [
                'name' => 'Payment Voucher',
                'summary' => 'Authorise and record money the business pays out.',
                'icon' => 'wallet',
                'accent' => 'orange',
                'binding' => false,
                'fields' => [
                    ['key' => 'payee_name', 'label' => 'Paid to', 'required' => true],
                    ['key' => 'amount', 'label' => 'Amount', 'required' => true],
                    ['key' => 'amount_words', 'label' => 'Amount in words', 'required' => true],
                    ['key' => 'purpose', 'label' => 'Purpose of payment', 'type' => 'textarea', 'required' => true],
                    ['key' => 'payment_method', 'label' => 'Paid by', 'default' => 'bank transfer'],
                    ['key' => 'reference', 'label' => 'Cheque / transfer reference'],
                    ['key' => 'prepared_by', 'label' => 'Prepared by', 'required' => true],
                    ['key' => 'approved_by', 'label' => 'Approved by', 'required' => true],
                ],
                'body' => <<<'TXT'
                ## Payment Voucher

                **Payee:** {{ payee_name }}

                **Amount:** {{ amount }} ({{ amount_words }})

                **Method:** {{ payment_method }}[ · Reference: {{ reference }}]

                ## Purpose

                {{ purpose }}

                Prepared by {{ prepared_by }} and approved by {{ approved_by }} on {{ today }}. Payment received in full by the payee, whose signature below acknowledges it.
                TXT,
            ],

            'mou' => [
                'name' => 'Memorandum of Understanding',
                'summary' => 'Record an intended collaboration before a full contract.',
                'icon' => 'users',
                'accent' => 'blue',
                'binding' => true,
                'fields' => [
                    ['key' => 'partner_name', 'label' => 'Other party', 'required' => true],
                    ['key' => 'purpose', 'label' => 'Purpose of the collaboration', 'type' => 'textarea', 'required' => true],
                    ['key' => 'our_responsibilities', 'label' => 'Our responsibilities', 'type' => 'textarea', 'required' => true],
                    ['key' => 'their_responsibilities', 'label' => 'Their responsibilities', 'type' => 'textarea', 'required' => true],
                    ['key' => 'duration', 'label' => 'Duration', 'default' => '12 months from the date of signing'],
                ],
                'body' => <<<'TXT'
                ## Memorandum of Understanding

                This memorandum is made on {{ today }} between **{{ company.name }}**[ of {{ company.address }}] and **{{ partner_name }}** (together, "the Parties").

                ## Purpose

                {{ purpose }}

                ## Responsibilities of {{ company.name }}

                {{ our_responsibilities }}

                ## Responsibilities of {{ partner_name }}

                {{ their_responsibilities }}

                ## Term and nature

                This memorandum takes effect on signing and continues for {{ duration }}. It records the Parties' intentions in good faith; except for this clause and the confidentiality obligation below, it does not create legally binding obligations. Any binding arrangement will be set out in a subsequent written agreement.

                Each Party shall keep confidential the non-public information it learns from the other under this memorandum.
                TXT,
            ],

            'partnership_agreement' => [
                'name' => 'Partnership Agreement',
                'summary' => 'Shares, duties and exits between business partners.',
                'icon' => 'users',
                'accent' => 'purple',
                'binding' => true,
                'fields' => [
                    ['key' => 'partner_name', 'label' => 'Partner name', 'required' => true],
                    ['key' => 'business_purpose', 'label' => 'Business purpose', 'type' => 'textarea', 'required' => true],
                    ['key' => 'our_share', 'label' => 'Our share', 'required' => true, 'placeholder' => 'e.g. 60%'],
                    ['key' => 'their_share', 'label' => 'Their share', 'required' => true, 'placeholder' => 'e.g. 40%'],
                    ['key' => 'capital_contributions', 'label' => 'Capital contributions', 'type' => 'textarea', 'required' => true],
                    ['key' => 'profit_sharing', 'label' => 'Profit sharing', 'default' => 'in proportion to the shares above, after agreed expenses'],
                    ['key' => 'exit_notice', 'label' => 'Exit notice period', 'default' => '90 days'],
                ],
                'body' => <<<'TXT'
                ## Partnership Agreement

                This agreement is made on {{ today }} between **{{ company.name }}** and **{{ partner_name }}** (together, "the Partners").

                ## 1. Purpose

                The Partners agree to carry on the following business together: {{ business_purpose }}

                ## 2. Shares and capital

                Ownership is divided {{ our_share }} to {{ company.name }} and {{ their_share }} to {{ partner_name }}.

                {{ capital_contributions }}

                ## 3. Profits and losses

                Profits and losses are shared {{ profit_sharing }}.

                ## 4. Decisions

                Decisions outside the ordinary course of business — borrowing, admitting a partner, disposing of significant assets — require the agreement of all Partners.

                ## 5. Leaving the partnership

                A Partner may withdraw by giving {{ exit_notice }} written notice. The remaining Partners may buy out the leaving Partner's share at a fair value agreed between them or determined by an independent valuer.
                TXT,
            ],

            'tenancy_agreement' => [
                'name' => 'Tenancy Agreement',
                'summary' => 'Let a property or premises for a fixed term and rent.',
                'icon' => 'home',
                'accent' => 'teal',
                'binding' => true,
                'fields' => [
                    ['key' => 'tenant_name', 'label' => 'Tenant name', 'required' => true],
                    ['key' => 'property_address', 'label' => 'Property address', 'required' => true],
                    ['key' => 'term', 'label' => 'Term', 'required' => true, 'placeholder' => 'e.g. 12 months'],
                    ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
                    ['key' => 'rent', 'label' => 'Rent', 'required' => true, 'placeholder' => 'e.g. $500 per month, payable annually in advance'],
                    ['key' => 'deposit', 'label' => 'Security deposit'],
                    ['key' => 'permitted_use', 'label' => 'Permitted use', 'default' => 'residential purposes only'],
                ],
                'body' => <<<'TXT'
                ## Tenancy Agreement

                This agreement is made on {{ today }} between **{{ company.name }}** ("the Landlord") and **{{ tenant_name }}** ("the Tenant") for the premises at **{{ property_address }}**.

                ## 1. Term and rent

                The tenancy runs for {{ term }} from {{ start_date }}. The rent is {{ rent }}.[ A security deposit of {{ deposit }} is payable before the tenancy begins, refundable at its end less the cost of any damage beyond fair wear and tear.]

                ## 2. Use

                The premises are let for {{ permitted_use }}. The Tenant shall not sublet or part with possession without the Landlord's written consent.

                ## 3. Obligations

                The Tenant shall pay rent when due, keep the interior in good condition, and report defects promptly. The Landlord shall keep the structure and installations for water and electricity in repair and allow the Tenant quiet enjoyment of the premises.

                ## 4. Ending the tenancy

                Either party may end the tenancy at the end of the term by prior written notice in accordance with applicable law. The Landlord may end it earlier if rent is materially in arrears or the premises are used unlawfully.
                TXT,
            ],

            'loan_agreement' => [
                'name' => 'Loan Agreement',
                'summary' => 'Lend or borrow with amounts, schedule and default terms.',
                'icon' => 'banknotes',
                'accent' => 'orange',
                'binding' => true,
                'fields' => [
                    ['key' => 'borrower_name', 'label' => 'Borrower', 'required' => true],
                    ['key' => 'principal', 'label' => 'Amount lent', 'required' => true],
                    ['key' => 'principal_words', 'label' => 'Amount in words', 'required' => true],
                    ['key' => 'interest', 'label' => 'Interest', 'default' => 'interest-free'],
                    ['key' => 'repayment_schedule', 'label' => 'Repayment schedule', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'e.g. Six monthly instalments of $200, starting 1 September 2026.'],
                    ['key' => 'purpose', 'label' => 'Purpose of the loan'],
                ],
                'body' => <<<'TXT'
                ## Loan Agreement

                This agreement is made on {{ today }} between **{{ company.name }}** ("the Lender") and **{{ borrower_name }}** ("the Borrower").

                ## 1. The loan

                The Lender lends the Borrower **{{ principal }}** ({{ principal_words }}), {{ interest }}.[ The loan is made for the purpose of {{ purpose }}.]

                ## 2. Repayment

                {{ repayment_schedule }}

                The Borrower may repay early, in whole or in part, without penalty.

                ## 3. Default

                If an instalment is more than 14 days late, the outstanding balance becomes immediately due on written demand. The Borrower bears the reasonable costs of recovering overdue amounts.
                TXT,
            ],

            'completion_certificate' => [
                'name' => 'Job Completion Certificate',
                'summary' => 'Certify that contracted work is finished and accepted.',
                'icon' => 'check-circle',
                'accent' => 'green',
                'binding' => false,
                'fields' => [
                    ['key' => 'client_name', 'label' => 'Client name', 'required' => true],
                    ['key' => 'project_description', 'label' => 'Work completed', 'type' => 'textarea', 'required' => true],
                    ['key' => 'completion_date', 'label' => 'Completed on', 'type' => 'date', 'required' => true],
                    ['key' => 'site_location', 'label' => 'Site / location'],
                    ['key' => 'defects_period', 'label' => 'Defects liability period', 'default' => '90 days'],
                ],
                'body' => <<<'TXT'
                ## Certificate of Job Completion

                {{ company.name }} certifies that the following work, carried out for **{{ client_name }}**[ at {{ site_location }}], was completed on **{{ completion_date }}**:

                {{ project_description }}

                The work has been inspected and handed over. Defects attributable to workmanship reported within {{ defects_period }} of the completion date will be made good at no charge.

                Signed for {{ company.name }} on {{ today }}.
                TXT,
            ],

            'warranty_certificate' => [
                'name' => 'Warranty Certificate',
                'summary' => 'A dated warranty for goods sold or work done.',
                'icon' => 'check-circle',
                'accent' => 'blue',
                'binding' => true,
                'fields' => [
                    ['key' => 'customer_name', 'label' => 'Customer name', 'required' => true],
                    ['key' => 'item_description', 'label' => 'Goods or work covered', 'type' => 'textarea', 'required' => true],
                    ['key' => 'purchase_date', 'label' => 'Date of purchase', 'type' => 'date', 'required' => true],
                    ['key' => 'warranty_period', 'label' => 'Warranty period', 'required' => true, 'placeholder' => 'e.g. 12 months'],
                    ['key' => 'exclusions', 'label' => 'Not covered', 'type' => 'textarea',
                        'default' => 'Damage from misuse, accident, unauthorised repair or normal wear.'],
                ],
                'body' => <<<'TXT'
                ## Warranty Certificate

                {{ company.name }} warrants to **{{ customer_name }}** the following, supplied on {{ purchase_date }}:

                {{ item_description }}

                ## Cover

                For {{ warranty_period }} from the date of purchase, defects in materials or workmanship will be repaired or replaced at no charge, on presentation of this certificate.

                ## Not covered

                {{ exclusions }}

                This warranty is in addition to, and does not limit, any rights the customer has under applicable consumer law.
                TXT,
            ],

            'gate_pass' => [
                'name' => 'Gate Pass',
                'summary' => 'Authorise goods or equipment to leave the premises.',
                'icon' => 'cube',
                'accent' => 'slate',
                'binding' => false,
                'fields' => [
                    ['key' => 'carried_by', 'label' => 'Carried by', 'required' => true],
                    ['key' => 'items', 'label' => 'Items leaving', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'One line per item, with quantities and serial numbers where they exist.'],
                    ['key' => 'destination', 'label' => 'Destination', 'required' => true],
                    ['key' => 'reason', 'label' => 'Reason', 'default' => 'delivery to customer'],
                    ['key' => 'returnable', 'label' => 'Returnable?', 'default' => 'Not returnable'],
                    ['key' => 'authorised_by', 'label' => 'Authorised by', 'required' => true],
                ],
                'body' => <<<'TXT'
                ## Gate Pass

                The items listed below are authorised to leave the premises of {{ company.name }} on {{ today }}, carried by **{{ carried_by }}**, destined for **{{ destination }}**. Reason: {{ reason }}. {{ returnable }}.

                ## Items

                {{ items }}

                Authorised by {{ authorised_by }}. Security should verify the items against this list at the gate and retain a copy.
                TXT,
            ],

            'mutual_nda' => [
                'name' => 'Mutual NDA',
                'summary' => 'Both sides sharing confidential information — a two-way discussion.',
                'icon' => 'briefcase',
                'accent' => 'purple',
                'binding' => true,
                'fields' => [
                    ['key' => 'other_party', 'label' => 'Other party', 'required' => true],
                    ['key' => 'purpose', 'label' => 'Purpose of discussion', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'e.g. Evaluating a possible partnership or joint venture.'],
                    ['key' => 'duration_years', 'label' => 'Confidentiality period (years)', 'default' => '3'],
                ],
                'body' => <<<'TXT'
                ## 1. Parties

                This agreement is made on {{ today }} between **{{ company.name }}** and **{{ other_party }}** (together, "the Parties"), each of whom may disclose confidential information to the other.

                ## 2. Purpose

                The Parties wish to exchange confidential information for the following purpose:

                {{ purpose }}

                ## 3. Confidential information

                "Confidential information" means any non-public information disclosed by either Party to the other, in any form, whether or not marked confidential. It does not include information that is already public, was already lawfully known to the receiving Party, or is independently developed without reference to what was disclosed.

                ## 4. Obligations

                Whichever Party receives confidential information from the other shall:

                - Use it only for the purpose stated above.
                - Not disclose it to anyone else without the disclosing Party's written permission.
                - Protect it with at least the care it applies to its own confidential information.

                These obligations bind both Parties equally, regardless of which of them disclosed the information in question.

                ## 5. Duration

                These obligations continue for {{ duration_years }} years from the date of this agreement.

                ## 6. Return

                On request, the receiving Party shall return or destroy the other's confidential information and confirm in writing that it has done so.
                TXT,
            ],

            'ip_assignment' => [
                'name' => 'IP & Asset Assignment',
                'summary' => 'Move inventions, trademarks or copyright into the business.',
                'icon' => 'document',
                'accent' => 'blue',
                'binding' => true,
                'fields' => [
                    ['key' => 'assignor_name', 'label' => 'Assigned by', 'required' => true,
                        'placeholder' => 'e.g. the founder or contractor who created it'],
                    ['key' => 'asset_description', 'label' => 'What is being assigned', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'Describe the invention, design, trademark, code, content or other work.'],
                    ['key' => 'consideration', 'label' => 'In exchange for', 'default' => 'the sum of one dollar and other good and valuable consideration'],
                    ['key' => 'warranty_note', 'label' => 'Ownership warranty', 'default' => 'was created solely by the Assignor and is free of any third-party claim'],
                ],
                'body' => <<<'TXT'
                ## Assignment of Intellectual Property

                This assignment is made on {{ today }} by **{{ assignor_name }}** ("the Assignor") in favour of **{{ company.name }}** ("the Company").

                ## 1. What is assigned

                The Assignor irrevocably assigns to the Company all right, title and interest — including any patent, trademark, copyright, design and trade secret rights — in and to the following:

                {{ asset_description }}

                ## 2. Consideration

                This assignment is made in exchange for {{ consideration }}.

                ## 3. Warranty

                The Assignor warrants that the assigned work {{ warranty_note }}, and that the Assignor has full authority to make this assignment.

                ## 4. Further assurance

                The Assignor shall sign any further document reasonably needed to record the Company's ownership, including with a trademark or patent office.
                TXT,
            ],

            'contractor_agreement' => [
                'name' => 'Independent Contractor Agreement',
                'summary' => 'Clear terms with a contractor — not an employee.',
                'icon' => 'briefcase',
                'accent' => 'teal',
                'binding' => true,
                'fields' => [
                    ['key' => 'contractor_name', 'label' => 'Contractor name', 'required' => true],
                    ['key' => 'services', 'label' => 'Services to be provided', 'type' => 'textarea', 'required' => true],
                    ['key' => 'fee', 'label' => 'Fee', 'required' => true, 'placeholder' => 'e.g. $40/hour, invoiced monthly'],
                    ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
                    ['key' => 'notice_period', 'label' => 'Notice period', 'default' => '14 days'],
                ],
                'body' => <<<'TXT'
                ## Independent Contractor Agreement

                This agreement is made on {{ today }} between **{{ company.name }}** ("the Company") and **{{ contractor_name }}** ("the Contractor").

                ## 1. Services

                The Contractor agrees to provide the following services, beginning {{ start_date }}:

                {{ services }}

                ## 2. Fee

                The Company shall pay {{ fee }}.

                ## 3. Independent contractor status

                The Contractor is engaged as an independent contractor, not an employee. The Contractor is responsible for their own taxes, insurance and statutory contributions, works without direct supervision as to method or hours, and may provide services to other clients. Nothing in this agreement creates an employment, partnership or agency relationship.

                ## 4. Ownership of work

                Work product created under this agreement belongs to the Company once paid for in full.

                ## 5. Ending the agreement

                Either party may end this agreement by giving {{ notice_period }} written notice.
                TXT,
            ],

            'employee_handbook' => [
                'name' => 'Employee Handbook',
                'summary' => 'Baseline workplace policies and conduct, in one document.',
                'icon' => 'document',
                'accent' => 'slate',
                'binding' => false,
                'fields' => [
                    ['key' => 'working_hours', 'label' => 'Working hours', 'default' => 'Monday to Friday, 8:00am to 5:00pm'],
                    ['key' => 'leave_policy', 'label' => 'Leave policy', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'Annual leave entitlement, how to request it, public holidays.'],
                    ['key' => 'conduct', 'label' => 'Code of conduct', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'Expected behaviour, punctuality, dress code, use of company property.'],
                    ['key' => 'disciplinary_process', 'label' => 'Disciplinary process', 'type' => 'textarea',
                        'default' => 'Verbal warning, written warning, final warning, then termination — each recorded on the employee\'s file.'],
                    ['key' => 'perks', 'label' => 'Perks and benefits', 'type' => 'textarea'],
                ],
                'body' => <<<'TXT'
                ## Employee Handbook — {{ company.name }}

                This handbook sets out the baseline policies that apply to everyone working at {{ company.name }}. It is not a contract of employment; where it conflicts with an individual's signed offer letter, the offer letter governs.

                ## Working hours

                {{ working_hours }}

                ## Leave

                {{ leave_policy }}

                ## Code of conduct

                {{ conduct }}

                ## Disciplinary process

                {{ disciplinary_process }}

                [## Perks and benefits

                {{ perks }}

                ]This handbook may be updated from time to time; staff will be told when it changes.
                TXT,
            ],

            'separation_agreement' => [
                'name' => 'Separation & Release Agreement',
                'summary' => 'A clean, signed-off end to employment, with a release of claims.',
                'icon' => 'document',
                'accent' => 'orange',
                'binding' => true,
                'fields' => [
                    ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                    ['key' => 'last_working_day', 'label' => 'Last working day', 'type' => 'date', 'required' => true],
                    ['key' => 'severance_terms', 'label' => 'Severance terms', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'Amount, what it covers, and when it will be paid.'],
                    ['key' => 'return_of_property', 'label' => 'Property to be returned', 'default' => 'Any laptop, keys, ID badge and other company property'],
                ],
                'body' => <<<'TXT'
                ## Separation & Release Agreement

                This agreement is made on {{ today }} between **{{ company.name }}** ("the Company") and **{{ employee_name }}** ("the Employee"), to record the terms on which the Employee's employment ends.

                ## 1. Separation

                The Employee's last working day is **{{ last_working_day }}**, after which the employment relationship ends.

                ## 2. Severance

                {{ severance_terms }}

                ## 3. Return of property

                {{ return_of_property }} shall be returned to the Company on or before the last working day.

                ## 4. Release

                In exchange for the severance above, the Employee releases the Company from any claim arising out of the employment or its ending, to the fullest extent permitted by law. This does not affect any right that cannot lawfully be released, such as an accrued statutory entitlement.

                ## 5. References

                The Company will confirm dates of employment and job title on request. Both parties agree to speak of the other professionally.

                Signing below confirms that the Employee has read this agreement and enters into it voluntarily.
                TXT,
            ],

            'msa' => [
                'name' => 'Master Services Agreement',
                'summary' => 'The core terms for an ongoing business-to-business service.',
                'icon' => 'document',
                'accent' => 'blue',
                'binding' => true,
                'fields' => [
                    ['key' => 'client_name', 'label' => 'Client name', 'required' => true],
                    ['key' => 'services_overview', 'label' => 'Services covered', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'The general kind of work this agreement will cover — specifics go in each Statement of Work.'],
                    ['key' => 'payment_terms', 'label' => 'Payment terms', 'default' => '14 days from invoice date'],
                    ['key' => 'termination_notice', 'label' => 'Termination notice', 'default' => '30 days'],
                ],
                'body' => <<<'TXT'
                ## Master Services Agreement

                This agreement is made on {{ today }} between **{{ company.name }}** ("the Provider") and **{{ client_name }}** ("the Client"), and governs the following services:

                {{ services_overview }}

                ## 1. Statements of Work

                Specific projects, deliverables, timelines and fees are set out in individual Statements of Work signed under this agreement. Where a Statement of Work conflicts with this agreement, this agreement governs unless the Statement of Work says otherwise in writing.

                ## 2. Payment

                Unless a Statement of Work says otherwise, invoices are payable within {{ payment_terms }}.

                ## 3. Confidentiality

                Each party shall keep confidential any non-public information it learns about the other through this agreement.

                ## 4. Liability

                Neither party is liable for indirect or consequential loss. Each party's liability under this agreement is limited to the fees paid under the Statement of Work giving rise to the claim.

                ## 5. Term and termination

                This agreement continues until ended by either party giving {{ termination_notice }} written notice, without affecting any Statement of Work already in progress.
                TXT,
            ],

            'sow' => [
                'name' => 'Statement of Work',
                'summary' => 'One project — deliverables, timeline and fees — under a master agreement.',
                'icon' => 'document',
                'accent' => 'green',
                'binding' => true,
                'fields' => [
                    ['key' => 'client_name', 'label' => 'Client name', 'required' => true],
                    ['key' => 'project_name', 'label' => 'Project name', 'required' => true],
                    ['key' => 'deliverables', 'label' => 'Deliverables', 'type' => 'textarea', 'required' => true],
                    ['key' => 'timeline', 'label' => 'Timeline', 'required' => true, 'placeholder' => 'e.g. 6 weeks from the start date'],
                    ['key' => 'fees', 'label' => 'Fees', 'required' => true],
                    ['key' => 'msa_reference', 'label' => 'Under the master agreement dated'],
                ],
                'body' => <<<'TXT'
                ## Statement of Work: {{ project_name }}

                Issued {{ today }} between **{{ company.name }}** and **{{ client_name }}**.[ This Statement of Work is issued under the Master Services Agreement dated {{ msa_reference }}.]

                ## 1. Deliverables

                {{ deliverables }}

                ## 2. Timeline

                {{ timeline }}

                ## 3. Fees

                {{ fees }}

                ## 4. Acceptance

                Deliverables are deemed accepted unless the Client raises a written objection within 5 business days of delivery.
                TXT,
            ],

            'website_tos' => [
                'name' => 'Website Terms of Service',
                'summary' => 'Rules for anyone using your website or app.',
                'icon' => 'document',
                'accent' => 'slate',
                'binding' => true,
                'fields' => [
                    ['key' => 'website_url', 'label' => 'Website address', 'required' => true],
                    ['key' => 'service_description', 'label' => 'What the site offers', 'type' => 'textarea', 'required' => true],
                    ['key' => 'governing_law', 'label' => 'Governing law', 'placeholder' => 'e.g. the laws of Cameroon'],
                ],
                'body' => <<<'TXT'
                ## Terms of Service — {{ website_url }}

                These terms govern use of {{ website_url }} ("the Site"), operated by {{ company.name }}. By using the Site you agree to them.

                ## 1. The service

                {{ service_description }}

                ## 2. Accounts

                If the Site requires an account, you are responsible for keeping your login details secure and for activity that happens under your account.

                ## 3. Acceptable use

                You will not use the Site to break the law, infringe anyone's rights, or interfere with its operation or other users.

                ## 4. Content

                Content on the Site belongs to {{ company.name }} or its licensors unless stated otherwise. You may not copy or redistribute it without permission.

                ## 5. Disclaimer

                The Site is provided as available, without a guarantee that it will be uninterrupted or error-free.

                [## 6. Governing law

                These terms are governed by {{ governing_law }}.

                ]{{ company.name }} may update these terms from time to time; continued use after a change means you accept it.
                TXT,
            ],

            'customer_privacy_policy' => [
                'name' => 'Privacy Policy',
                'summary' => 'What you collect from customers and how you use it.',
                'icon' => 'document',
                'accent' => 'purple',
                'binding' => true,
                'fields' => [
                    ['key' => 'data_collected', 'label' => 'What you collect', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'e.g. name, email, phone, delivery address, payment details.'],
                    ['key' => 'data_use', 'label' => 'How you use it', 'type' => 'textarea', 'required' => true],
                    ['key' => 'data_sharing', 'label' => 'Who you share it with', 'default' => 'We do not sell customer data. It is shared only with providers who help us operate — payment processors, delivery partners — under confidentiality obligations.'],
                    ['key' => 'contact_email', 'label' => 'Privacy contact email', 'required' => true],
                ],
                'body' => <<<'TXT'
                ## Privacy Policy

                {{ company.name }} collects and uses personal information as described here.

                ## 1. What we collect

                {{ data_collected }}

                ## 2. How we use it

                {{ data_use }}

                ## 3. Sharing

                {{ data_sharing }}

                ## 4. Your rights

                You may ask us what personal information we hold about you, ask us to correct it, or ask us to delete it, by writing to {{ contact_email }}.

                ## 5. Changes

                We may update this policy from time to time; the current version always applies.
                TXT,
            ],

            'board_resolution' => [
                'name' => 'Board Resolution',
                'summary' => 'The formal record of one specific decision.',
                'icon' => 'document',
                'accent' => 'slate',
                'binding' => true,
                'fields' => [
                    ['key' => 'meeting_date', 'label' => 'Date of meeting', 'type' => 'date', 'required' => true],
                    ['key' => 'attendees', 'label' => 'Directors present', 'type' => 'textarea', 'required' => true],
                    ['key' => 'resolution_text', 'label' => 'It was resolved that…', 'type' => 'textarea', 'required' => true,
                        'placeholder' => 'State the decision precisely, e.g. "the Company shall open a bank account with..."'],
                    ['key' => 'proposed_by', 'label' => 'Proposed by'],
                    ['key' => 'seconded_by', 'label' => 'Seconded by'],
                ],
                'body' => <<<'TXT'
                ## Resolution of the Directors of {{ company.name }}

                At a meeting held on {{ meeting_date }}, attended by:

                {{ attendees }}

                ## Resolution

                IT WAS RESOLVED THAT:

                {{ resolution_text }}

                [Proposed by {{ proposed_by }}[ and seconded by {{ seconded_by }}].

                ]This resolution was passed and takes effect from the date above.
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
