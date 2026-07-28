<?php

namespace App\Livewire\Business;

use App\Services\LogoComposer;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * Logo generator (Module 1). Configurations render live; saving writes the SVG
 * to storage and points the company at it, so every template that shows a logo
 * picks it up immediately.
 */
class Logo extends Component
{
    public string $palette = 'ocean';

    public string $mark = 'orbit';

    public string $layout = 'horizontal';

    public string $tagline = '';

    public function mount(): void
    {
        $company = app(CurrentCompany::class)->get();

        $this->tagline = (string) $company->motto;

        // Open on something already suited to the industry rather than a blank
        // default — the first screen should look like a plausible answer.
        [$palette, $mark] = LogoComposer::INDUSTRY_HINTS[$company->industry] ?? ['ocean', 'orbit'];
        $this->palette = $palette;
        $this->mark = $mark;
    }

    public function applySuggestion(string $palette, string $mark, string $layout): void
    {
        $this->palette = $palette;
        $this->mark = $mark;
        $this->layout = $layout;
    }

    public function save(LogoComposer $composer): void
    {
        $company = app(CurrentCompany::class)->get();

        $svg = $composer->render($company->name, $this->tagline, [
            'palette' => $this->palette,
            'mark' => $this->mark,
            'layout' => $this->layout,
        ]);

        $path = 'logos/'.$company->id.'/logo-'.now()->timestamp.'.svg';
        Storage::disk('public')->put($path, $svg);

        $company->forceFill([
            'logo_path' => $path,
            // The brand kit records the choices, so later assets stay consistent
            // and the logo can be regenerated at any size from its config.
            'brand_tokens' => array_merge($company->brand_tokens ?? [], [
                'palette' => $this->palette,
                'primary' => LogoComposer::PALETTES[$this->palette][0],
                'secondary' => LogoComposer::PALETTES[$this->palette][1],
                'mark' => $this->mark,
                'layout' => $this->layout,
            ]),
        ])->save();

        session()->flash('logoStatus', 'Logo saved. It now appears on your profile and stationery.');
    }

    public function render(LogoComposer $composer): View
    {
        $company = app(CurrentCompany::class)->get();

        return view('livewire.business.logo', [
            'company' => $company,
            'preview' => $composer->render($company->name, $this->tagline, [
                'palette' => $this->palette,
                'mark' => $this->mark,
                'layout' => $this->layout,
            ]),
            'suggestions' => collect($composer->suggestions((string) $company->industry, 6))
                ->map(fn (array $option) => $option + [
                    'svg' => $composer->render($company->name, '', $option, 260),
                ])
                ->all(),
        ])->layout('components.layouts.app', ['title' => 'Logo', 'active' => 'business']);
    }
}
