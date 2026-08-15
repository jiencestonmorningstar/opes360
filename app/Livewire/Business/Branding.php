<?php

namespace App\Livewire\Business;

use App\Support\BrandDefaults;
use App\Support\BrandPalette;
use App\Support\Colour;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Where a business chooses how the platform looks to it.
 *
 * The screen's real job is not collecting a hex code — it is showing the owner
 * what their choice does before they live with it. So the preview is a slice of
 * an actual dashboard rather than a row of swatches: a swatch cannot show you
 * that your green makes button labels vanish, and the whole reason this module
 * derives colours instead of accepting them is that the failures are invisible
 * at swatch size. Stripe's indigo is 4.18 against this app's canvas, a rounding
 * error under the 4.5 floor, and nobody catches that by eye.
 *
 * Each derived role therefore prints its computed contrast ratio. The guarantee
 * is worth more if the owner can watch it hold.
 *
 * Behind `business.manage-branding`, the same right that governs the logo — it
 * is the same decision about how the business presents itself.
 */
class Branding extends Component
{
    use AuthorizesRequests;

    public string $primary = '';

    public string $secondary = '';

    public string $neutral = 'cool';

    public string $radius = 'rounded';

    public string $density = 'comfortable';

    public string $skin = 'solid';

    public float $glass_strength = 0.5;

    public function mount(): void
    {
        $this->authorize('business.manage-branding');

        foreach ($this->company()->brandingInputs() as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = is_float($this->{$key}) ? (float) $value : (string) $value;
            }
        }
    }

    protected function company()
    {
        return app(CurrentCompany::class)->get();
    }

    protected function rules(): array
    {
        return [
            // A hex rule rather than a colour-picker type: the value arrives
            // over the wire and a browser is not the only thing that can send
            // it. BrandPalette falls back on garbage anyway, but a silent
            // fallback is a worse answer than telling the owner.
            'primary' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'secondary' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'neutral' => ['required', Rule::in(BrandDefaults::neutralNames())],
            'radius' => ['required', Rule::in(BrandDefaults::radiusNames())],
            'density' => ['required', Rule::in(BrandDefaults::densityNames())],
            'skin' => ['required', Rule::in(BrandDefaults::skinNames())],
            'glass_strength' => ['required', 'numeric', 'min:0', 'max:1'],
        ];
    }

    protected function messages(): array
    {
        return [
            'primary.regex' => 'That is not a colour. Use a hex value such as #1d4ed8.',
            'secondary.regex' => 'That is not a colour. Use a hex value such as #7e22ce.',
        ];
    }

    /** The palette for whatever is currently on screen, saved or not. */
    public function previewPalette(): array
    {
        return BrandPalette::derive($this->inputs());
    }

    /** @return array<string, mixed> */
    protected function inputs(): array
    {
        return [
            'primary' => Colour::isHex($this->primary) ? $this->primary : BrandDefaults::inputs()['primary'],
            'secondary' => Colour::isHex($this->secondary) ? $this->secondary : BrandDefaults::inputs()['secondary'],
            'neutral' => $this->neutral,
            'radius' => $this->radius,
            'density' => $this->density,
            'skin' => $this->skin,
            'glass_strength' => $this->glass_strength,
        ];
    }

    /**
     * What each derived role actually scores, so the owner can see the
     * guarantee holding rather than take it on trust.
     *
     * @return array<int, array{label: string, colour: string, on: string, ratio: float}>
     */
    public function contrastReport(): array
    {
        $t = $this->previewPalette()['light'];

        $rows = [
            ['Brand text', $t['--color-brand'], $t['--color-canvas'], 'on the page background'],
            ['Brand button', $t['--color-fill-brand'], '#ffffff', 'under white label text'],
            ['Second colour', $t['--color-secondary'], $t['--color-canvas'], 'on the page background'],
            ['Body text', $t['--color-ink'], $t['--color-canvas'], 'on the page background'],
            ['Quiet text', $t['--color-faint'], $t['--color-canvas'], 'on the page background'],
        ];

        return array_map(fn ($r) => [
            'label' => $r[0],
            'colour' => $r[1],
            'on' => $r[2],
            'where' => $r[3],
            'ratio' => round(Colour::contrast($r[1], $r[2]), 2),
        ], $rows);
    }

    public function save(): void
    {
        $this->authorize('business.manage-branding');

        $this->validate();

        $this->company()->forceFill(['branding' => $this->inputs()])->save();

        session()->flash('status', 'Your branding is saved. It applies everywhere at once.');

        // A full reload rather than a Livewire patch: the palette lives in a
        // <style> block in the document head, which a component re-render
        // cannot reach.
        $this->redirect(route('business.branding'), navigate: false);
    }

    public function resetToDefault(): void
    {
        $this->authorize('business.manage-branding');

        $this->company()->forceFill(['branding' => null])->save();

        session()->flash('status', 'Branding reset to the Opes360 default.');

        $this->redirect(route('business.branding'), navigate: false);
    }

    /** Ready-made starting points, so nobody faces an empty colour field. */
    public function presets(): array
    {
        return [
            ['name' => 'Opes blue', 'primary' => '#1d4ed8', 'secondary' => '#7e22ce'],
            ['name' => 'Aubergine', 'primary' => '#4a154b', 'secondary' => '#b91c60'],
            ['name' => 'Forest', 'primary' => '#166534', 'secondary' => '#115e59'],
            ['name' => 'Ink', 'primary' => '#1f2937', 'secondary' => '#0369a1'],
            ['name' => 'Terracotta', 'primary' => '#9a3412', 'secondary' => '#7c2d12'],
            ['name' => 'Indigo', 'primary' => '#635bff', 'secondary' => '#0891b2'],
        ];
    }

    public function applyPreset(string $primary, string $secondary): void
    {
        $this->primary = $primary;
        $this->secondary = $secondary;
    }

    public function render(): View
    {
        return view('livewire.business.branding', [
            'preview' => $this->previewPalette(),
            'report' => $this->contrastReport(),
        ])->layout('components.layouts.app', ['active' => 'business', 'title' => 'Branding']);
    }
}
