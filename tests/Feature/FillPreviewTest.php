<?php

namespace Tests\Feature;

use App\Livewire\Papers\Compose;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The live previews on the two fill-in screens.
 *
 * A user typing into a template or an invoice form is shown the finished sheet
 * as they go. For papers the preview is server-composed, so what it shows is
 * exactly what will be stored; for sales documents it is drawn client-side from
 * the form state, so these tests pin the markup the client draws into.
 */
class FillPreviewTest extends TestCase
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

    public function test_the_paper_preview_shows_the_merged_text_as_typed(): void
    {
        Livewire::actingAs($this->user)
            ->test(Compose::class, ['template' => 'service_agreement'])
            ->set('fields.client_name', 'Blue Co')
            ->set('fields.fee', '$2,500 per month')
            // The answers, already merged into the template's sentences.
            ->assertSee('Blue Co')
            ->assertSee('$2,500 per month')
            // The letterhead, so the sheet on screen is the sheet that prints.
            ->assertSee('Acme Ltd')
            ->assertSee('12 Broad Street')
            ->assertSee('Draft — not issued')
            // A binding template carries its review notice into the preview.
            ->assertSee(DocumentTemplates::reviewNotice())
            ->assertSeeHtml('data-preview="paper"');
    }

    public function test_the_paper_preview_updates_when_a_field_changes(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(Compose::class, ['template' => 'service_agreement'])
            ->set('fields.client_name', 'Blue Co')
            ->assertSee('Blue Co');

        // The preview follows the field, not the first thing typed into it.
        $component->set('fields.client_name', 'Green Co')
            ->assertSee('Green Co')
            ->assertDontSee('Blue Co');
    }

    public function test_the_sales_create_page_renders_with_its_preview(): void
    {
        // A customer on file, as the page normally has.
        Contact::create(['name' => 'A Customer']);

        $this->actingAs($this->user)
            ->get(route('documents.create', ['type' => 'invoice']))
            ->assertOk()
            // The hook the client-side preview is drawn into…
            ->assertSee('data-preview="document"', false)
            // …with the letterhead name already injected server-side.
            ->assertSee('Acme Ltd')
            ->assertSee('Billed To');
    }
}
