<?php

namespace Tests\Feature;

use App\Livewire\Forms\Builder;
use App\Livewire\Forms\Index;
use App\Models\Company;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\FormFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Module 16 — Opes Forms.
 *
 * The public fill page is the part with teeth: it validates entirely from the
 * stored field definitions, so the builder and the validator must agree on
 * every type the builder can produce.
 */
class FormsTest extends TestCase
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
            'plan' => 'business',
        ]);

        $this->joinCompany($this->company, $this->user);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function makeForm(array $fields = [], string $status = 'open'): Form
    {
        return Form::create([
            'title' => 'Registration',
            'status' => $status,
            'share_token' => Form::newShareToken(),
            'fields' => $fields ?: [
                ['id' => 'f-name', 'type' => 'short_text', 'label' => 'Your name', 'required' => true, 'options' => []],
                ['id' => 'f-email', 'type' => 'email', 'label' => 'Email', 'required' => false, 'options' => []],
                ['id' => 'f-day', 'type' => 'choice', 'label' => 'Day', 'required' => true, 'options' => ['Friday', 'Saturday']],
            ],
        ]);
    }

    public function test_forms_pages_render_for_the_owner(): void
    {
        $form = $this->makeForm();

        $this->actingAs($this->user)->get('/forms')->assertOk()->assertSee('Registration');
        $this->actingAs($this->user)->get('/forms/'.$form->id.'/build')->assertOk()->assertSee('Your name');
    }

    public function test_index_creates_a_draft_and_redirects_to_the_builder(): void
    {
        Livewire::actingAs($this->user)
            ->test(Index::class)
            ->call('createForm')
            ->assertRedirect();

        $this->assertDatabaseHas('forms', [
            'company_id' => $this->company->id,
            'title' => 'Untitled form',
            'status' => 'draft',
        ]);
    }

    public function test_builder_adds_fields_and_persists_them(): void
    {
        $form = $this->makeForm(status: 'draft');

        Livewire::actingAs($this->user)
            ->test(Builder::class, ['form' => $form])
            ->call('addField', 'choice')
            ->call('addOption', 3);

        $fields = $form->fresh()->fields;

        $this->assertCount(4, $fields);
        $this->assertSame('choice', $fields[3]['type']);
        $this->assertCount(2, $fields[3]['options']);
    }

    public function test_a_form_with_no_labelled_fields_cannot_open(): void
    {
        $form = Form::create([
            'title' => 'Empty',
            'status' => 'draft',
            'share_token' => Form::newShareToken(),
            'fields' => [],
        ]);

        Livewire::actingAs($this->user)
            ->test(Builder::class, ['form' => $form])
            ->call('setStatus', 'open')
            ->assertHasErrors('fields');

        $this->assertSame('draft', $form->fresh()->status);
    }

    public function test_public_page_renders_an_open_form(): void
    {
        $form = $this->makeForm();

        $this->get('/f/'.$form->share_token)
            ->assertOk()
            ->assertSee('Registration')
            ->assertSee('Your name')
            ->assertSee('Friday');
    }

    public function test_submission_validates_from_the_field_definitions(): void
    {
        $form = $this->makeForm();

        // Missing required, bad email, option not in the catalogue.
        $this->post('/f/'.$form->share_token, [
            'answers' => ['f-email' => 'not-an-email', 'f-day' => 'Sunday'],
        ])->assertSessionHasErrors(['answers.f-name', 'answers.f-email', 'answers.f-day']);

        $this->assertSame(0, FormResponse::count());
    }

    public function test_a_valid_submission_is_stored_with_answers_keyed_by_field(): void
    {
        $form = $this->makeForm();

        $this->post('/f/'.$form->share_token, [
            'answers' => ['f-name' => 'Ada', 'f-day' => 'Saturday', 'unknown' => 'dropped'],
        ])->assertRedirect('/f/'.$form->share_token.'/thanks');

        $response = FormResponse::sole();

        $this->assertSame('Ada', $response->answers['f-name']);
        $this->assertSame('Saturday', $response->answers['f-day']);
        $this->assertArrayNotHasKey('unknown', $response->answers);
        $this->assertSame($this->company->id, $response->company_id);
    }

    /**
     * FormFields::submissionRules() used to build the "in:" rule by joining
     * options with commas, which silently split any option containing a
     * comma into two — an answer no one could ever pick would validate as
     * legitimate. Rule::in() must be used instead.
     */
    public function test_an_option_containing_a_comma_still_validates_correctly(): void
    {
        $form = $this->makeForm(fields: [
            ['id' => 'f-topics', 'type' => 'checkboxes', 'label' => 'Topics', 'required' => true, 'options' => ['Invoicing, Inventory', 'Offline mode']],
        ]);

        $this->post('/f/'.$form->share_token, [
            'answers' => ['f-topics' => ['Invoicing, Inventory']],
        ])->assertRedirect('/f/'.$form->share_token.'/thanks');

        $this->assertSame(['Invoicing, Inventory'], FormResponse::sole()->answers['f-topics']);

        // A value matching only half of the comma-containing option must
        // still be rejected.
        $this->post('/f/'.$form->share_token, [
            'answers' => ['f-topics' => ['Invoicing']],
        ])->assertSessionHasErrors();
    }

    public function test_a_closed_form_rejects_submissions(): void
    {
        $form = $this->makeForm(status: 'closed');

        $this->get('/f/'.$form->share_token)->assertOk()->assertSee('not accepting responses');

        $this->post('/f/'.$form->share_token, [
            'answers' => ['f-name' => 'Ada', 'f-day' => 'Friday'],
        ])->assertRedirect('/f/'.$form->share_token);

        $this->assertSame(0, FormResponse::count());
    }

    public function test_responses_page_needs_the_responses_permission(): void
    {
        $form = $this->makeForm();

        // Cashier has no forms grants at all.
        $cashier = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->joinCompany($this->company, $cashier, Role::CASHIER);

        $this->actingAs($cashier)->get('/forms/'.$form->id.'/responses')->assertForbidden();
        $this->actingAs($this->user)->get('/forms/'.$form->id.'/responses')->assertOk();
    }

    public function test_responses_are_invisible_across_companies(): void
    {
        $form = $this->makeForm();

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'rival',
            'name' => 'Rival Ltd',
            'owner_id' => $otherOwner->id,
            'currency' => 'USD',
        ]);
        $this->joinCompany($other, $otherOwner);
        $otherOwner->forceFill(['current_company_id' => $other->id])->save();

        $this->actingAs($otherOwner)->get('/forms/'.$form->id.'/responses')->assertNotFound();
    }

    public function test_csv_export_carries_one_column_per_field(): void
    {
        $form = $this->makeForm();

        FormResponse::create([
            'form_id' => $form->id,
            'answers' => ['f-name' => 'Ada', 'f-day' => 'Friday'],
        ]);

        $response = $this->actingAs($this->user)->get('/forms/'.$form->id.'/responses.csv');

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Your name', $csv);
        $this->assertStringContainsString('Ada', $csv);
        $this->assertStringContainsString('Friday', $csv);
    }

    public function test_every_builder_type_produces_rules_the_validator_understands(): void
    {
        foreach (array_keys(FormFields::TYPES) as $type) {
            $field = FormFields::blank($type);
            $field['label'] = 'Q';

            ['rules' => $rules] = FormFields::submissionRules([$field]);

            $this->assertArrayHasKey('answers.'.$field['id'], $rules, "No rules produced for {$type}");
        }
    }
}
