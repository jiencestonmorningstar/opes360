<?php

namespace Tests\Feature\Guides;

use App\Livewire\Guides\Index;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Guides;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class GuidesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    // ── The catalogue and its files must agree, both ways ─────────────────

    /**
     * A feature nobody can find out how to use is not finished. These two
     * tests are the enforcement: a catalogued guide with no file fails, and a
     * file nobody catalogued fails too.
     */
    public function test_every_catalogued_guide_has_a_file(): void
    {
        foreach (Guides::slugs() as $slug) {
            $this->assertFileExists(Guides::path($slug), "Guide [{$slug}] is catalogued but has no file.");
        }
    }

    public function test_every_guide_file_is_catalogued(): void
    {
        foreach (glob(resource_path('guides/*.md')) as $file) {
            $slug = basename($file, '.md');

            $this->assertTrue(
                Guides::exists($slug),
                "Guide file [{$slug}.md] exists but is not in the catalogue, so nobody can reach it.",
            );
        }
    }

    public function test_every_guide_has_a_title_group_summary_and_audience(): void
    {
        foreach (Guides::all() as $slug => $guide) {
            foreach (['title', 'group', 'summary', 'audience'] as $key) {
                $this->assertArrayHasKey($key, $guide, "{$slug} is missing {$key}");
                $this->assertNotSame('', $guide[$key], "{$slug} has an empty {$key}");
            }

            $this->assertContains($guide['audience'], ['everyone', 'admin', 'developer'], $slug);
        }
    }

    public function test_no_guide_is_an_empty_page(): void
    {
        foreach (Guides::slugs() as $slug) {
            $this->assertGreaterThan(
                200,
                strlen(Guides::body($slug)),
                "Guide [{$slug}] is a stub rather than a guide.",
            );
        }
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    public function test_markdown_becomes_html(): void
    {
        $html = Guides::html('departments');

        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('Departments', $html);
    }

    /**
     * These files are written by us, but a documentation page that renders raw
     * HTML is one that will eventually render somebody's script tag.
     */
    public function test_html_inside_a_guide_is_escaped_rather_than_rendered(): void
    {
        $path = resource_path('guides/tmp-escaping-check.md');
        file_put_contents($path, "# Check\n\n<script>alert('x')</script>\n");

        try {
            $html = Str::markdown(file_get_contents($path), [
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]);

            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
        } finally {
            @unlink($path);
        }
    }

    // ── The screen ────────────────────────────────────────────────────────

    public function test_the_screen_opens_on_the_starting_guide(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->assertSee('How this documentation works')
            ->assertSee('Departments');
    }

    public function test_a_guide_can_be_opened_from_the_contents(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->call('open', 'approvals')
            ->assertSee('Approvals and workflows')
            ->assertSee('Reject');
    }

    public function test_an_unknown_slug_falls_back_rather_than_breaking(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->call('open', 'not-a-guide')
            ->assertOk()
            ->assertSee('How this documentation works');
    }

    public function test_search_finds_a_guide_by_its_body_not_just_its_title(): void
    {
        $this->actingAs($this->owner);

        // "quorum" appears nowhere in any title or summary.
        Livewire::test(Index::class)
            ->set('search', 'hand over')
            ->assertSee('Approvals and workflows');
    }

    public function test_search_with_no_matches_says_so(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->set('search', 'zzzzznothingmatchesthis')
            ->assertSee('Nothing matches');
    }

    public function test_an_empty_search_shows_everything(): void
    {
        $this->assertCount(count(Guides::slugs()), Guides::search(''));
    }

    // ── Reach ─────────────────────────────────────────────────────────────

    public function test_the_route_opens_for_anybody_signed_in(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($cashier);

        $this->get(route('guides'))->assertOk();
    }

    public function test_a_guide_can_be_linked_to_directly(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('guides.show', 'document-security'))
            ->assertOk()
            ->assertSee('Who can see a document');
    }
}
