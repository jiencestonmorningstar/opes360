<?php

namespace Tests\Feature;

use App\Support\DocumentTemplates;
use Tests\TestCase;

/**
 * The 9 templates added for commercial and HR operations: SaaS/MSA + SOW,
 * website ToS + customer privacy policy, mutual NDA alongside the existing
 * one-way NDA, IP & asset assignment, contractor agreement, employee
 * handbook, separation agreement, and board resolutions. Deliberately
 * excludes startup-formation documents (founder equity, vesting, bylaws) —
 * those need a lawyer, not a template, and don't fit this platform's SMB
 * audience.
 */
class NewBusinessDocumentTemplatesTest extends TestCase
{
    public function test_the_new_templates_exist(): void
    {
        $keys = [
            'mutual_nda',
            'ip_assignment',
            'contractor_agreement',
            'employee_handbook',
            'separation_agreement',
            'msa',
            'sow',
            'website_tos',
            'customer_privacy_policy',
            'board_resolution',
        ];

        foreach ($keys as $key) {
            $this->assertTrue(DocumentTemplates::exists($key), "Template [{$key}] should exist.");
        }

        $this->assertCount(36, DocumentTemplates::all());
    }

    public function test_the_existing_nda_is_now_explicitly_one_way(): void
    {
        $template = DocumentTemplates::find('nda');

        $this->assertSame('One-Way NDA', $template['name']);
    }

    public function test_no_founder_or_equity_documents_were_added(): void
    {
        // These need a lawyer, not a template — see the class docblock.
        foreach (['founder_agreement', 'bylaws', 'vesting_agreement', 'piiia'] as $key) {
            $this->assertFalse(DocumentTemplates::exists($key));
        }
    }
}
