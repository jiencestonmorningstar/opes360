<?php

namespace Tests\Feature;

use App\Support\BrandDefaults;
use App\Support\BrandPalette;
use App\Support\Colour;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The hostile-seed corpus.
 *
 * This is the centre of gravity for the whole branding module. It is the
 * difference between "we generate palettes" and "we cannot generate a broken
 * palette". If one of these fails, the generator is wrong — never loosen an
 * assertion to make it pass.
 */
class BrandPaletteTest extends TestCase
{
    /** Colours chosen to break a naive generator. */
    public static function hostileSeeds(): array
    {
        return [
            'Spotify green' => ['#1db954'],
            'Airbnb red' => ['#ff385c'],
            'Stripe indigo' => ['#635bff'],
            'Shopify green' => ['#008060'],
            'pure yellow' => ['#ffff00'],
            'pure cyan' => ['#00ffff'],
            'pure magenta' => ['#ff00ff'],
            'pure black' => ['#000000'],
            'pure white' => ['#ffffff'],
            'near white' => ['#fefefe'],
            'near black' => ['#010101'],
            'mid grey' => ['#808080'],
            'deep aubergine' => ['#4a154b'],
        ];
    }

    protected function palette(string $seed, array $extra = []): array
    {
        return BrandPalette::derive(array_merge(BrandDefaults::inputs(), ['primary' => $seed], $extra));
    }

    #[DataProvider('hostileSeeds')]
    public function test_every_seed_yields_a_readable_light_palette(string $seed): void
    {
        $t = $this->palette($seed)['light'];

        foreach (['brand', 'secondary'] as $role) {
            foreach (['canvas', 'surface', 'surface-2'] as $surface) {
                $this->assertGreaterThanOrEqual(
                    4.5,
                    Colour::contrast($t["--color-{$role}"], $t["--color-{$surface}"]),
                    "{$role} ink on {$surface} for seed {$seed}",
                );
            }

            $this->assertGreaterThanOrEqual(
                4.5,
                Colour::contrast($t["--color-fill-{$role}"], '#ffffff'),
                "white text on {$role} fill for seed {$seed}",
            );

            $this->assertGreaterThanOrEqual(
                4.5,
                Colour::contrast($t["--color-{$role}"], $t["--color-tint-{$role}"]),
                "{$role} ink on its own tint for seed {$seed}",
            );
        }
    }

    #[DataProvider('hostileSeeds')]
    public function test_every_seed_yields_a_readable_dark_palette(string $seed): void
    {
        $t = $this->palette($seed)['dark'];

        foreach (['brand', 'secondary'] as $role) {
            foreach (['canvas', 'surface', 'surface-2'] as $surface) {
                $this->assertGreaterThanOrEqual(
                    4.5,
                    Colour::contrast($t["--color-{$role}"], $t["--color-{$surface}"]),
                    "{$role} ink on dark {$surface} for seed {$seed}",
                );
            }

            $this->assertGreaterThanOrEqual(
                4.5,
                Colour::contrast($t["--color-fill-{$role}"], '#ffffff'),
                "white text on dark {$role} fill for seed {$seed}",
            );
        }
    }

    #[DataProvider('hostileSeeds')]
    public function test_the_text_ramp_survives_every_seed(string $seed): void
    {
        foreach (['light', 'dark'] as $mode) {
            $t = $this->palette($seed)[$mode];

            foreach (['ink', 'ink-2', 'muted', 'faint'] as $step) {
                foreach (['canvas', 'surface', 'surface-2'] as $surface) {
                    $this->assertGreaterThanOrEqual(
                        4.5,
                        Colour::contrast($t["--color-{$step}"], $t["--color-{$surface}"]),
                        "{$step} on {$mode} {$surface} for seed {$seed}",
                    );
                }
            }
        }
    }

    #[DataProvider('hostileSeeds')]
    public function test_every_accent_survives_every_seed(string $seed): void
    {
        foreach (['light', 'dark'] as $mode) {
            $t = $this->palette($seed)[$mode];

            foreach (['green', 'orange', 'purple', 'teal', 'pink', 'slate'] as $family) {
                foreach (['canvas', 'surface', 'surface-2'] as $surface) {
                    $this->assertGreaterThanOrEqual(
                        4.5,
                        Colour::contrast($t["--color-accent-{$family}"], $t["--color-{$surface}"]),
                        "accent-{$family} on {$mode} {$surface} for seed {$seed}",
                    );
                }

                $this->assertGreaterThanOrEqual(
                    4.5,
                    Colour::contrast($t["--color-accent-{$family}"], $t["--color-tint-{$family}"]),
                    "accent-{$family} on its tint in {$mode} for seed {$seed}",
                );

                $this->assertGreaterThanOrEqual(
                    4.5,
                    Colour::contrast($t["--color-fill-{$family}"], '#ffffff'),
                    "white text on fill-{$family} in {$mode} for seed {$seed}",
                );
            }
        }
    }

    /** Every neutral ramp must work, not just the cool one the design was tuned on. */
    public function test_every_neutral_ramp_is_readable(): void
    {
        foreach (BrandDefaults::neutralNames() as $neutral) {
            foreach (['light', 'dark'] as $mode) {
                $t = $this->palette('#1db954', ['neutral' => $neutral])[$mode];

                foreach (['ink', 'faint', 'brand'] as $role) {
                    foreach (['canvas', 'surface', 'surface-2'] as $surface) {
                        $this->assertGreaterThanOrEqual(
                            4.5,
                            Colour::contrast($t["--color-{$role}"], $t["--color-{$surface}"]),
                            "{$role} on {$mode} {$surface} for the {$neutral} ramp",
                        );
                    }
                }
            }
        }
    }

    public function test_semantic_colours_are_not_brandable(): void
    {
        $a = $this->palette('#1db954');
        $b = $this->palette('#ff385c');

        foreach (['positive', 'warning', 'negative'] as $role) {
            $this->assertSame(
                $a['light']["--color-{$role}"],
                $b['light']["--color-{$role}"],
                "{$role} moved with the brand — overdue must never be able to turn green",
            );
        }
    }

    public function test_the_legacy_hue_aliases_are_populated(): void
    {
        $t = $this->palette('#1db954')['light'];

        // 2453 view references use these names directly.
        foreach (['--color-accent-blue', '--color-fill-blue', '--color-tint-blue', '--color-tint-red'] as $alias) {
            $this->assertArrayHasKey($alias, $t);
            $this->assertTrue(Colour::isHex($t[$alias]), "{$alias} is not a hex colour");
        }

        $this->assertSame($t['--color-brand'], $t['--color-accent-blue']);
    }

    public function test_density_and_radius_land_in_the_token_map(): void
    {
        $compact = $this->palette('#1d4ed8', ['density' => 'compact'])['root'];
        $comfy = $this->palette('#1d4ed8', ['density' => 'comfortable'])['root'];

        $this->assertNotSame($compact['--spacing'], $comfy['--spacing']);

        $this->assertSame('0px', $this->palette('#1d4ed8', ['radius' => 'sharp'])['root']['--radius-card']);
        $this->assertSame('1.75rem', $this->palette('#1d4ed8', ['radius' => 'pill'])['root']['--radius-card']);
    }

    public function test_glass_tokens_appear_only_for_the_glass_skin(): void
    {
        $this->assertSame('0px', $this->palette('#1d4ed8', ['skin' => 'solid'])['root']['--glass-blur']);
        $this->assertNotSame('0px', $this->palette('#1d4ed8', ['skin' => 'glass'])['root']['--glass-blur']);
    }

    public function test_glass_strength_is_clamped(): void
    {
        foreach ([-5, 99, 'nonsense'] as $absurd) {
            $root = $this->palette('#1d4ed8', ['skin' => 'glass', 'glass_strength' => $absurd])['root'];

            preg_match('/([\d.]+)/', $root['--glass-blur'], $m);
            $this->assertLessThanOrEqual(24.0, (float) $m[1], 'blur escaped its ceiling');
            $this->assertGreaterThanOrEqual(8.0, (float) $m[1], 'blur escaped its floor');
        }
    }

    /** Garbage in must not produce a broken palette. */
    public function test_an_invalid_seed_falls_back_instead_of_exploding(): void
    {
        $t = BrandPalette::derive(array_merge(BrandDefaults::inputs(), ['primary' => 'not-a-colour']))['light'];

        $this->assertTrue(Colour::isHex($t['--color-brand']));
        $this->assertGreaterThanOrEqual(4.5, Colour::contrast($t['--color-brand'], $t['--color-canvas']));
    }

    /** The generator must be able to reproduce a known-good design. */
    public function test_the_platform_default_reproduces_todays_brand_blue(): void
    {
        $t = BrandPalette::derive(BrandDefaults::inputs())['light'];

        $this->assertSame('#1d4ed8', $t['--color-brand'], 'the default seed no longer survives as the brand ink');
        $this->assertSame('#ffffff', $t['--color-surface']);
        $this->assertSame('#eef2f7', $t['--color-canvas']);
    }

    public function test_partial_inputs_are_completed_by_defaults(): void
    {
        $t = BrandPalette::derive(['primary' => '#4a154b']);

        $this->assertSame('0.25rem', $t['root']['--spacing']);
        $this->assertSame('#eef2f7', $t['light']['--color-canvas']);
    }
}
