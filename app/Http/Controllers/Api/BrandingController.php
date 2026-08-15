<?php

namespace App\Http\Controllers\Api;

use App\Support\BrandDefaults;
use App\Support\BrandPalette;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A business's branding, over HTTP.
 *
 * Reading is behind `read` and `business.view`, because a palette is not
 * sensitive — a customer-facing page renders it anyway, and an integrator
 * building a companion app needs the colours to match. Writing is behind
 * `write` and `business.manage-branding`, the same right the screen uses.
 *
 * The response carries both the inputs and the derived palette. The inputs are
 * what a client should echo back when updating; the palette is what it should
 * paint with. Returning only the inputs would force every consumer to
 * reimplement the OKLCH derivation and get the contrast guarantee wrong, which
 * defeats the point of deriving centrally.
 */
class BrandingController extends ApiController
{
    public function show(): JsonResponse
    {
        $this->authorize('business.view');

        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('business.manage-branding');

        $data = $request->validate([
            'primary' => ['sometimes', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'secondary' => ['sometimes', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'neutral' => ['sometimes', Rule::in(BrandDefaults::neutralNames())],
            'radius' => ['sometimes', Rule::in(BrandDefaults::radiusNames())],
            'density' => ['sometimes', Rule::in(BrandDefaults::densityNames())],
            'skin' => ['sometimes', Rule::in(BrandDefaults::skinNames())],
            'glass_strength' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $company = app(CurrentCompany::class)->get();

        // Merged over what is already stored, so a client that only wants to
        // change the corner radius does not have to resend the colours and
        // risk clobbering them with stale values.
        $company->forceFill([
            'branding' => array_merge($company->brandingInputs(), $data),
        ])->save();

        return response()->json(['data' => $this->payload()]);
    }

    /**
     * Reset to the platform default. A DELETE rather than a PUT of nulls,
     * because "I want no branding of my own" is a different statement from
     * "set my brand colour to nothing".
     */
    public function destroy(): JsonResponse
    {
        $this->authorize('business.manage-branding');

        app(CurrentCompany::class)->get()->forceFill(['branding' => null])->save();

        return response()->json(['data' => $this->payload()]);
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        $company = app(CurrentCompany::class)->get();

        return [
            'inputs' => $company->brandingInputs(),
            'palette' => BrandPalette::for($company),
            'options' => [
                'neutral' => BrandDefaults::neutralNames(),
                'radius' => BrandDefaults::radiusNames(),
                'density' => BrandDefaults::densityNames(),
                'skin' => BrandDefaults::skinNames(),
            ],
        ];
    }
}
