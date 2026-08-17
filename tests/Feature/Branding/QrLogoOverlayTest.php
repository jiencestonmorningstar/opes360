<?php

namespace Tests\Feature\Branding;

use App\Models\Company;
use App\Models\User;
use App\Services\QrCodes;
use BaconQrCode\Common\ErrorCorrectionLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Let the QR codes always have company icon in the middle to enforce
 * branding" — every QR this product prints goes through QrCodes::svg(),
 * so the overlay lives there once rather than being copy-pasted into every
 * print controller call site.
 */
class QrLogoOverlayTest extends TestCase
{
    use RefreshDatabase;

    protected function companyWithLogo(): Company
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);

        $path = Storage::disk('public')->putFileAs(
            'branding',
            UploadedFile::fake()->image('logo.png', 200, 200),
            'logo.png',
        );

        $company->forceFill(['logo_path' => $path])->save();

        return $company->fresh();
    }

    public function test_a_qr_with_no_company_has_no_logo_overlay(): void
    {
        $svg = app(QrCodes::class)->svg('https://opes360.com/v/abc');

        $this->assertStringNotContainsString('<image', $svg);
    }

    public function test_a_qr_for_a_company_with_a_logo_gets_a_centre_overlay(): void
    {
        $company = $this->companyWithLogo();

        $svg = app(QrCodes::class)->svg('https://opes360.com/v/abc', 512, 2, null, $company);

        $this->assertStringContainsString('<image', $svg);
        $this->assertStringContainsString('data:image/png;base64,', $svg);
        $this->assertStringContainsString('fill="#ffffff"', $svg); // the backing plate
    }

    public function test_a_company_with_no_logo_gets_no_overlay_even_when_asked(): void
    {
        $owner = User::factory()->create();
        $company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);

        $svg = app(QrCodes::class)->svg('https://opes360.com/v/abc', 512, 2, null, $company);

        $this->assertStringNotContainsString('<image', $svg);
    }

    public function test_the_overlay_is_never_added_at_error_correction_level_m(): void
    {
        $company = $this->companyWithLogo();

        // A code printed very small (business card) opts into M for scan
        // reliability — a centre mark there risks the code not scanning at
        // all, so the overlay only ever applies at the default H level.
        $svg = app(QrCodes::class)->svg('https://opes360.com/v/abc', 200, 2, ErrorCorrectionLevel::M(), $company);

        $this->assertStringNotContainsString('<image', $svg);
    }

    public function test_the_overlay_still_produces_valid_looking_svg(): void
    {
        $company = $this->companyWithLogo();

        $svg = app(QrCodes::class)->svg('https://opes360.com/v/abc', 512, 2, null, $company);

        $this->assertStringStartsWith('<?xml', ltrim($svg));
        $this->assertStringEndsWith('</svg>', trim($svg));
    }
}
