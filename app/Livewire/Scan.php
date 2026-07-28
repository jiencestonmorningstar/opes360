<?php

namespace App\Livewire;

use App\Models\Scopes\CompanyScope;
use App\Models\VerificationToken;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Scan or paste an OPES360 code to look it up.
 *
 * Camera scanning happens client-side (BarcodeDetector where available), which
 * keeps it working without a round trip. The manual field is not a fallback for
 * unsupported browsers only — reading a code off a printed page and typing it is
 * a legitimate way to check a document.
 */
class Scan extends Component
{
    public string $code = '';

    public function lookup(): void
    {
        $token = trim($this->code);

        // Accept a full URL as well as a bare token: people paste what they have.
        if (str_contains($token, '/v/')) {
            $token = trim(parse_url($token, PHP_URL_PATH) ?? '', '/');
            $token = str_replace('v/', '', $token);
        }

        if ($token === '') {
            $this->addError('code', 'Enter or scan a code first.');

            return;
        }

        $exists = VerificationToken::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('token', $token)
            ->exists();

        if (! $exists) {
            $this->addError('code', 'That code does not match any OPES360 record.');

            return;
        }

        $this->redirectRoute('verification.show', $token);
    }

    public function render(): View
    {
        return view('livewire.scan')
            ->layout('components.layouts.app', ['title' => 'Scan', 'active' => 'home']);
    }
}
