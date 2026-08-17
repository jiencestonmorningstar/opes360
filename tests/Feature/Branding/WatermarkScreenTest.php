<?php

namespace Tests\Feature\Branding;

use App\Livewire\Business\Watermark;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WatermarkScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->owner->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
        $this->actingAs($this->owner);
    }

    public function test_generating_a_seal_saves_it_and_records_the_chosen_design(): void
    {
        Livewire::test(Watermark::class)
            ->call('chooseDesign', 'shield')
            ->call('saveGenerated');

        $fresh = $this->company->fresh();
        $this->assertSame('shield', $fresh->watermark_seal);
        $this->assertNotNull($fresh->watermark_path);
        Storage::disk('public')->assertExists($fresh->watermark_path);
    }

    public function test_an_unknown_design_key_is_refused_silently_in_favour_of_the_default(): void
    {
        $component = Livewire::test(Watermark::class)->call('chooseDesign', 'not-a-real-design');

        $component->assertSet('design', 'starburst');
    }

    public function test_uploading_a_custom_image_saves_it_and_clears_the_seal_design(): void
    {
        Livewire::test(Watermark::class)
            ->set('upload', UploadedFile::fake()->image('mark.png', 400, 400))
            ->call('saveUpload');

        $fresh = $this->company->fresh();
        $this->assertNull($fresh->watermark_seal);
        $this->assertNotNull($fresh->watermark_path);
        Storage::disk('public')->assertExists($fresh->watermark_path);
    }

    public function test_the_watermark_cannot_be_turned_on_before_one_exists(): void
    {
        Livewire::test(Watermark::class)
            ->call('toggle')
            ->assertStatus(422);

        $this->assertFalse($this->company->fresh()->show_watermark);
    }

    public function test_toggling_flips_show_watermark_once_one_exists(): void
    {
        $component = Livewire::test(Watermark::class)->call('saveGenerated');

        $component->call('toggle');
        $this->assertTrue($this->company->fresh()->show_watermark);

        $component->call('toggle');
        $this->assertFalse($this->company->fresh()->show_watermark);
    }

    public function test_removing_clears_the_watermark_and_turns_it_off(): void
    {
        Livewire::test(Watermark::class)->call('saveGenerated')->call('toggle');
        $this->assertTrue($this->company->fresh()->show_watermark);

        Livewire::test(Watermark::class)->call('remove');

        $fresh = $this->company->fresh();
        $this->assertNull($fresh->watermark_path);
        $this->assertNull($fresh->watermark_seal);
        $this->assertFalse($fresh->show_watermark);
    }

    public function test_a_role_without_branding_permission_cannot_generate_a_watermark(): void
    {
        $clerk = User::factory()->create();
        $this->joinCompany($this->company, $clerk, Role::SALES_OFFICER);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $this->actingAs($clerk);

        Livewire::test(Watermark::class)->call('saveGenerated')->assertStatus(403);
    }
}
