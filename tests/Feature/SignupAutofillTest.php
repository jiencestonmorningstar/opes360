<?php

namespace Tests\Feature;

use App\Livewire\Onboarding\Register;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The signup form must tell the browser what its fields are.
 *
 * A password manager decides what a field is by looking at it. Given an
 * explicit `autocomplete` token it obeys; given nothing it guesses from the
 * label, the placeholder and the field's position in the form. "Motto" means
 * nothing to that classifier, and it sat in a form the browser had already
 * decided was a signup — so it was offered as a place to put a password.
 *
 * Two things caused it and both are pinned here. The page had no <form> element
 * at all, so every control in the document — the two password fields of step 1
 * and the six business fields of step 2 — landed in one synthetic form the
 * browser invented. And step 2 declared no autocomplete on anything.
 */
class SignupAutofillTest extends TestCase
{
    use RefreshDatabase;

    /** Step 2, rendered the way a person reaches it. */
    protected function businessStep(): string
    {
        return Livewire::test(Register::class)
            ->set('name', 'Ada Ngu')
            ->set('email', 'ada@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('passwordConfirmation', 'correct-horse-battery')
            ->call('continueToBusiness')
            ->assertHasNoErrors()
            ->html();
    }

    /**
     * The motto is a phrase a business chose. It is not a credential, it is not
     * worth remembering, and it must never be offered as somewhere to put one.
     */
    public function test_the_motto_field_declares_itself_not_a_credential(): void
    {
        $html = $this->businessStep();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*wire:model="motto"[^>]*autocomplete="off"/s',
            $html,
            'The motto field must declare autocomplete="off".'
        );

        // `autocomplete="off"` is the standards answer and browsers honour it.
        // Third-party managers largely ignore it, so each one gets the opt-out
        // it actually reads.
        $motto = $this->fieldTag($html, 'motto');

        foreach ([
            'data-1p-ignore' => '1Password',
            'data-lpignore="true"' => 'LastPass',
            'data-bwignore' => 'Bitwarden',
            'data-form-type="other"' => 'Dashlane',
        ] as $attribute => $vendor) {
            $this->assertStringContainsString(
                $attribute, $motto, "The motto field needs {$attribute} for {$vendor}."
            );
        }
    }

    /**
     * The structural half: business fields and password fields must not share a
     * form. They cannot here, because step 2 renders no password field — but
     * that only holds while each step is wrapped in a form of its own, which is
     * what stops the browser inventing one around the whole document.
     */
    public function test_the_business_step_is_a_form_of_its_own_with_no_password_field(): void
    {
        $html = $this->businessStep();

        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertStringContainsString('wire:submit="finish"', $html);
    }

    public function test_the_account_step_is_a_form_of_its_own(): void
    {
        $html = Livewire::test(Register::class)->html();

        $this->assertStringContainsString('wire:submit="continueToBusiness"', $html);

        // Both password fields still say what they are, so a manager offers to
        // generate one rather than guessing at it.
        $this->assertSame(2, substr_count($html, 'autocomplete="new-password"'));
    }

    /**
     * The invariant, rather than the instance.
     *
     * Motto was the field that got mistaken, but nothing about it was special —
     * it was simply undeclared, and so was every field beside it. A field added
     * here later with no token would be the same bug again, so the rule is that
     * every field on this form declares its purpose.
     */
    public function test_every_field_on_the_signup_form_declares_its_purpose(): void
    {
        foreach ([Livewire::test(Register::class)->html(), $this->businessStep()] as $html) {
            preg_match_all('/<(input|select)\b[^>]*wire:model="([^"]+)"[^>]*>/s', $html, $matches, PREG_SET_ORDER);

            $this->assertNotEmpty($matches, 'No fields found — the selector has drifted.');

            foreach ($matches as [$tag, , $model]) {
                $this->assertMatchesRegularExpression(
                    '/\bautocomplete="[^"]+"/', $tag,
                    "The `{$model}` field declares no autocomplete, so the browser will guess at it."
                );
            }
        }
    }

    /** The whole point is that it still works. */
    public function test_the_form_still_creates_the_business(): void
    {
        $this->seed(RolePermissionSeeder::class);

        Livewire::test(Register::class)
            ->set('name', 'Ada Ngu')
            ->set('email', 'ada@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('passwordConfirmation', 'correct-horse-battery')
            ->call('continueToBusiness')
            ->set('businessName', 'Ngu Trading')
            ->set('motto', 'Built to last')
            ->call('finish')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('companies', [
            'name' => 'Ngu Trading',
            'motto' => 'Built to last',
        ]);
    }

    /** The opening tag of one field, for attribute assertions. */
    protected function fieldTag(string $html, string $model): string
    {
        preg_match('/<input\b[^>]*wire:model="'.preg_quote($model, '/').'"[^>]*>/s', $html, $m);

        $this->assertNotEmpty($m, "No field found for wire:model=\"{$model}\".");

        return $m[0];
    }
}
