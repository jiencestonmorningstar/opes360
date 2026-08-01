<?php

namespace Tests\Feature;

use App\Livewire\Business\Stationery;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\User;
use App\Services\DocumentComposer;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The letterhead designs. Every design must produce a printable sheet that
 * still carries the verification QR — a prettier letterhead that can no longer
 * be checked would be a step backwards — and the same design must follow the
 * business onto its generated documents.
 */
class LetterheadDesignsTest extends TestCase
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
            'motto' => 'Built to last',
            'address_line1' => '12 Broad Street',
            'city' => 'Lagos',
            'country' => 'NG',
            // Papers is a Growth-plan module and the column defaults to Basic.
            'plan' => 'growth',
        ]);

        $this->joinCompany($this->company, $this->user);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_every_design_renders_the_stationery_sheet_with_its_qr(): void
    {
        foreach (array_keys(Stationery::LETTERHEAD_DESIGNS) as $design) {
            $this->company->forceFill(['letterhead_design' => $design])->save();

            $content = $this->actingAs($this->user)
                ->get(route('stationery.print', ['asset' => 'letterhead']))
                ->assertOk()
                ->assertSee('Acme Ltd')
                ->assertSee('design-'.$design)
                ->getContent();

            $this->assertStringContainsString('<svg', $content, "The {$design} letterhead lost its QR.");
        }
    }

    public function test_every_design_renders_a_composed_document_with_its_qr(): void
    {
        $paper = $this->issuedPaper();

        foreach (array_keys(Stationery::LETTERHEAD_DESIGNS) as $design) {
            $this->company->forceFill(['letterhead_design' => $design])->save();

            $content = $this->actingAs($this->user)
                ->get(route('papers.print', $paper))
                ->assertOk()
                ->assertSee($paper->title)
                ->assertSee('design-'.$design)
                ->getContent();

            $this->assertStringContainsString('<svg', $content, "The {$design} paper lost its QR.");
        }
    }

    public function test_a_business_that_never_chose_gets_the_original_rule_design(): void
    {
        $this->assertNull($this->company->letterhead_design);

        $this->actingAs($this->user)
            ->get(route('stationery.print', ['asset' => 'letterhead']))
            ->assertOk()
            ->assertSee('design-rule');
    }

    public function test_the_picker_saves_the_chosen_design(): void
    {
        Livewire::actingAs($this->user)
            ->test(Stationery::class)
            ->call('setLetterheadDesign', 'banner')
            ->assertSet('letterheadDesign', 'banner');

        $this->assertSame('banner', $this->company->fresh()->letterhead_design);
    }

    public function test_an_unknown_design_is_ignored_by_the_picker(): void
    {
        Livewire::actingAs($this->user)
            ->test(Stationery::class)
            ->call('setLetterheadDesign', 'hologram')
            ->assertSet('letterheadDesign', 'rule');

        $this->assertNull($this->company->fresh()->letterhead_design);
    }

    public function test_the_picker_reopens_on_the_saved_design(): void
    {
        $this->company->forceFill(['letterhead_design' => 'crest'])->save();

        Livewire::actingAs($this->user)
            ->test(Stationery::class)
            ->assertSet('letterheadDesign', 'crest');
    }

    protected function issuedPaper(): BusinessDocument
    {
        $composer = app(DocumentComposer::class);

        $paper = BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Alpha Builders — supervision',
            'recipient' => 'Alpha Builders Ltd',
            'status' => 'draft',
            'fields' => ['client_name' => 'Alpha Builders Ltd'],
            'body' => $composer->merge(
                'service_agreement',
                ['client_name' => 'Alpha Builders Ltd', 'fee' => '$100'],
                $this->company,
            ),
            'created_by' => $this->user->id,
        ]);

        return $composer->issue($paper, $this->user);
    }
}
