<?php

namespace Tests\Feature;

use App\Livewire\Papers\Compose;
use App\Livewire\Papers\Show;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentComposer;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Module 13 — the document generator.
 *
 * These documents get signed, so the bar is the same as for an invoice: what is
 * on the paper is what is in the database, it cannot be edited afterwards, and
 * anyone holding a copy can check both.
 */
class DocumentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
            'email' => 'hello@acme.test',
            'address_line1' => '12 Broad Street',
            'city' => 'Lagos',
            'country' => 'NG',
        ]);

        $this->joinCompany($this->company, $this->user);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function composer(): DocumentComposer
    {
        return app(DocumentComposer::class);
    }

    public function test_every_template_composes_without_leaving_a_placeholder(): void
    {
        foreach (array_keys(DocumentTemplates::all()) as $key) {
            $body = $this->composer()->merge($key, [], $this->company);

            // An unfilled placeholder reaching the page is the failure that
            // matters most here: it goes straight onto paper someone signs.
            $this->assertStringNotContainsString('{{', $body, "Template [{$key}] left a placeholder.");
            $this->assertStringNotContainsString('}}', $body, "Template [{$key}] left a placeholder.");
        }
    }

    public function test_an_optional_clause_disappears_when_it_has_no_value(): void
    {
        $withAddress = $this->composer()->merge('service_agreement', ['client_name' => 'Blue Co'], $this->company);
        $this->assertStringContainsString('of 12 Broad Street, Lagos, NG', $withAddress);

        $this->company->forceFill(['address_line1' => null, 'city' => null, 'country' => null])->save();

        $without = $this->composer()->merge('service_agreement', ['client_name' => 'Blue Co'], $this->company->fresh());

        // Not "between Acme Ltd of  ("the Provider")" — the whole clause goes.
        $this->assertStringContainsString('**Acme Ltd** ("the Provider")', $without);
        $this->assertStringNotContainsString(' of  ', $without);
    }

    public function test_dates_are_written_the_way_a_person_writes_them(): void
    {
        $body = $this->composer()->merge('service_agreement', [
            'client_name' => 'Blue Co',
            'start_date' => '2026-08-01',
        ], $this->company);

        $this->assertStringContainsString('1 August 2026', $body);
        $this->assertStringNotContainsString('2026-08-01', $body);
    }

    public function test_a_skipped_optional_field_does_not_leave_a_dangling_label(): void
    {
        $body = $this->composer()->merge('employment_letter', [
            'employee_name' => 'Ada',
            'job_title' => 'Fitter',
        ], $this->company);

        // "Reports to:" with nothing after it reads as a mistake.
        $this->assertStringNotContainsString('Reports to:', $body);
    }

    public function test_user_text_cannot_smuggle_markup_into_the_document(): void
    {
        $html = $this->composer()->toHtml(
            $this->composer()->merge('service_agreement', [
                'client_name' => '<script>alert(1)</script>',
            ], $this->company)
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_composing_and_issuing_produces_a_verifiable_document(): void
    {
        Livewire::actingAs($this->user)
            ->test(Compose::class, ['template' => 'service_agreement'])
            ->set('title', 'Alpha Builders — supervision')
            ->set('fields.client_name', 'Alpha Builders Ltd')
            ->set('fields.services', 'Monthly site supervision.')
            ->set('fields.fee', '$2,500 per month')
            ->set('fields.start_date', '2026-08-01')
            ->call('saveAndIssue')
            ->assertRedirect();

        $paper = BusinessDocument::firstOrFail()->load('verificationToken');
        $year = now()->format('Y');

        $this->assertSame('issued', $paper->status);
        $this->assertSame("DOC-{$year}-00001", $paper->reference);
        $this->assertSame('Alpha Builders Ltd', $paper->recipient);
        $this->assertNotNull($paper->content_hash);
        $this->assertNotNull($paper->verification_token_id);
        $this->assertFalse($paper->isTampered());

        // A stranger holding the paper can check it, with no session.
        app(CurrentCompany::class)->set(null);

        $this->get('/v/'.$paper->verificationToken->token)
            ->assertOk()
            ->assertSee('Verified')
            ->assertSee($paper->reference);
    }

    public function test_an_issued_document_cannot_be_edited(): void
    {
        $paper = $this->issuedPaper();

        $this->expectException(RuntimeException::class);
        $paper->update(['body' => 'Something else entirely.']);
    }

    public function test_an_issued_document_is_not_reachable_from_the_editor(): void
    {
        $paper = $this->issuedPaper();

        $this->actingAs($this->user)->get(route('papers.edit', $paper))->assertForbidden();
    }

    public function test_the_body_is_frozen_rather_than_rendered_on_read(): void
    {
        $paper = $this->issuedPaper();

        // Stored once at save. A later edit to the template library must not be
        // able to rewrite a document someone has already signed.
        $this->assertStringContainsString('Alpha Builders Ltd', $paper->body);
        $this->assertSame($paper->body, $paper->fresh()->body);
    }

    public function test_a_tampered_body_is_detected(): void
    {
        $paper = $this->issuedPaper();

        // Straight past the model guard, as a database-level edit would be.
        BusinessDocument::withoutEvents(fn () => $paper->forceFill([
            'body' => str_replace('Alpha Builders Ltd', 'Someone Else Ltd', $paper->body),
        ])->saveQuietly());

        $this->assertTrue($paper->fresh()->isTampered());

        $token = $paper->verificationToken->token;

        app(CurrentCompany::class)->set(null);
        $this->get("/v/{$token}")->assertOk()->assertSee('Content mismatch');
    }

    public function test_voiding_revokes_the_token_so_a_scan_reports_void(): void
    {
        $paper = $this->issuedPaper();
        $token = $paper->verificationToken->token;

        Livewire::actingAs($this->user)
            ->test(Show::class, ['paper' => $paper])
            ->call('openVoid')
            ->set('voidReason', 'Superseded')
            ->call('voidPaper')
            ->assertHasNoErrors();

        $this->assertSame('void', $paper->fresh()->status);

        app(CurrentCompany::class)->set(null);
        $this->get("/v/{$token}")->assertOk()->assertSee('Voided');
    }

    public function test_a_cashier_cannot_reach_the_document_library(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        // A till operator has no business reading the company's contracts.
        $this->actingAs($cashier)->get(route('papers'))->assertForbidden();
    }

    public function test_a_sales_officer_may_draft_but_not_issue(): void
    {
        $officer = User::factory()->create();
        $this->joinCompany($this->company, $officer, Role::SALES_OFFICER);
        $officer->forceFill(['current_company_id' => $this->company->id])->save();

        Livewire::actingAs($officer)
            ->test(Compose::class, ['template' => 'business_proposal'])
            ->set('title', 'Proposal')
            ->set('fields.client_name', 'Someone')
            ->set('fields.objective', 'A thing')
            ->set('fields.approach', 'Carefully')
            ->set('fields.investment', '$100')
            ->call('save', false)
            ->assertRedirect();

        $this->assertSame('draft', BusinessDocument::firstOrFail()->status);

        Livewire::actingAs($officer)
            ->test(Compose::class, ['template' => 'business_proposal'])
            ->set('title', 'Another')
            ->set('fields.client_name', 'Someone')
            ->set('fields.objective', 'A thing')
            ->set('fields.approach', 'Carefully')
            ->set('fields.investment', '$100')
            ->call('saveAndIssue')
            ->assertForbidden();
    }

    public function test_a_document_from_another_company_is_not_reachable(): void
    {
        $paper = $this->issuedPaper();

        $otherOwner = User::factory()->create();
        $other = Company::create(['slug' => 'other', 'name' => 'Other Ltd', 'owner_id' => $otherOwner->id]);
        $this->joinCompany($other, $otherOwner);
        $otherOwner->forceFill(['current_company_id' => $other->id])->save();

        $this->actingAs($otherOwner)->get(route('papers.show', $paper))->assertNotFound();
    }

    protected function issuedPaper(): BusinessDocument
    {
        $paper = BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Alpha Builders — supervision',
            'recipient' => 'Alpha Builders Ltd',
            'status' => 'draft',
            'fields' => ['client_name' => 'Alpha Builders Ltd'],
            'body' => $this->composer()->merge(
                'service_agreement',
                ['client_name' => 'Alpha Builders Ltd', 'fee' => '$100'],
                $this->company,
            ),
            'created_by' => $this->user->id,
        ]);

        return $this->composer()->issue($paper, $this->user);
    }
}
