<?php

namespace App\Livewire\Settings;

use App\Support\TokenAbilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Where a user mints and revokes their own API tokens.
 *
 * This screen is the difference between having an API and having an API
 * anybody can safely use. Without it the only way to get a token is to POST
 * an email and password, which means handing an integration the credentials to
 * the whole account — including the ability to change the password and lock
 * the owner out. A token is revocable and can be scoped; a password is
 * neither.
 *
 * The token is shown exactly once, on the screen that created it. Storing it
 * anywhere retrievable would make the hash pointless.
 */
class ApiTokens extends Component
{
    public string $name = '';

    /** @var array<int, string> */
    public array $abilities = [TokenAbilities::READ];

    /** Shown once, immediately after minting, then never again. */
    public ?string $plainTextToken = null;

    /*
     * Gated on `settings.update`, the same trust webhooks demand for the same
     * reason: a token is a standing credential that outlives a password change,
     * so minting one is account configuration, not personal preference. There
     * is no dedicated ability for it, and `settings.view` is handed to every
     * role — a gate everybody passes is not a gate. Checked in mount() AND in
     * each action, because Livewire actions arrive without re-running mount.
     */
    public function mount(): void
    {
        Gate::authorize('settings.update');
    }

    public function create(): void
    {
        Gate::authorize('settings.update');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(TokenAbilities::all())],
        ], [
            'name.required' => 'Give this token a name you will recognise later.',
            'abilities.required' => 'Choose at least one thing this token may do.',
        ]);

        $token = auth()->user()->createToken($this->name, $this->abilities);

        $this->plainTextToken = $token->plainTextToken;
        $this->reset(['name']);
        $this->abilities = [TokenAbilities::READ];
    }

    public function revoke(string $id): void
    {
        Gate::authorize('settings.update');

        // Scoped to the signed-in user's own tokens: an id from somebody
        // else's account must not resolve here.
        auth()->user()->tokens()->whereKey($id)->delete();

        session()->flash('status', 'That token has been revoked.');
    }

    public function dismissToken(): void
    {
        $this->plainTextToken = null;
    }

    public function render(): View
    {
        return view('livewire.settings.api-tokens', [
            'tokens' => auth()->user()->tokens()->latest()->get(),
            'catalogue' => TokenAbilities::CATALOGUE,
        ])->layout('components.layouts.app', ['title' => 'API tokens', 'active' => 'settings']);
    }
}
