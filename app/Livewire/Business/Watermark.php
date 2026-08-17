<?php

namespace App\Livewire\Business;

use App\Services\SealComposer;
use App\Support\CurrentCompany;
use App\Support\SealCatalog;
use App\Support\UploadGate;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Choosing a printed-document watermark — one of the six generated seal
 * designs, or a custom image the business uploads. Mirrors Logo's
 * "generate or upload" shape, and Business\Edit's upload handling.
 */
class Watermark extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public string $design = 'starburst';

    public string $bottomText = '';

    public bool $showWatermark = false;

    public $upload = null;

    public function mount(): void
    {
        $company = app(CurrentCompany::class)->get();

        $this->design = $company->watermark_seal ?? 'starburst';
        $this->showWatermark = (bool) $company->show_watermark;
    }

    public function chooseDesign(string $design): void
    {
        $this->authorize('business.manage-branding');

        $this->design = SealCatalog::exists($design) ? $design : 'starburst';
    }

    /** Generate and save the chosen seal design as the watermark. */
    public function saveGenerated(SealComposer $composer): void
    {
        $this->authorize('business.manage-branding');

        $company = app(CurrentCompany::class)->get();

        $svg = $composer->render(
            $company->name,
            trim($this->bottomText) ?: (string) $company->registration_number,
            $this->design,
            $company->brandToken('primary', '#1d4ed8'),
        );

        $path = 'watermarks/'.$company->id.'/seal-'.now()->timestamp.'.svg';
        Storage::disk('public')->put($path, $svg);

        $company->forceFill([
            'watermark_path' => $path,
            'watermark_seal' => $this->design,
        ])->save();

        session()->flash('watermarkStatus', 'Watermark generated and saved.');
    }

    /** Save a business-supplied image as the watermark instead of a generated seal. */
    public function saveUpload(): void
    {
        $this->authorize('business.manage-branding');

        $this->validate(['upload' => ['required', 'image', 'max:4096']]);

        try {
            app(UploadGate::class)->accept($this->upload, 'image');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['upload' => $e->getMessage()]);
        }

        $company = app(CurrentCompany::class)->get();

        $path = $this->upload->storeAs(
            'watermarks/'.$company->id,
            'custom-'.now()->timestamp.'.'.$this->upload->extension(),
            'public',
        );

        $company->forceFill([
            'watermark_path' => $path,
            'watermark_seal' => null, // it is no longer one of the generated designs
        ])->save();

        $this->upload = null;

        session()->flash('watermarkStatus', 'Custom watermark uploaded and saved.');
    }

    public function toggle(): void
    {
        $this->authorize('business.manage-branding');

        $company = app(CurrentCompany::class)->get();

        abort_if($company->watermark_path === null, 422, 'Generate or upload a watermark before turning it on.');

        $this->showWatermark = ! $this->showWatermark;
        $company->forceFill(['show_watermark' => $this->showWatermark])->save();
    }

    public function remove(): void
    {
        $this->authorize('business.manage-branding');

        $company = app(CurrentCompany::class)->get();

        if ($company->watermark_path) {
            Storage::disk('public')->delete($company->watermark_path);
        }

        $company->forceFill(['watermark_path' => null, 'watermark_seal' => null, 'show_watermark' => false])->save();
        $this->showWatermark = false;

        session()->flash('watermarkStatus', 'Watermark removed.');
    }

    public function render(SealComposer $composer): View
    {
        $company = app(CurrentCompany::class)->get();

        return view('livewire.business.watermark', [
            'company' => $company,
            'designs' => SealCatalog::designs(),
            'preview' => $composer->render(
                $company->name,
                trim($this->bottomText) ?: (string) $company->registration_number,
                $this->design,
                $company->brandToken('primary', '#1d4ed8'),
                320,
            ),
        ])->layout('components.layouts.app', ['title' => 'Watermark', 'active' => 'business']);
    }
}
