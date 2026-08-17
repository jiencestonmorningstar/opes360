<?php

namespace App\Services\Documents;

/**
 * Document kinds a module registers without Documents importing it — the
 * same extension shape DocumentFieldRegistry already established.
 *
 * DocumentKinds::all() is the closed catalogue of *built-in* kinds: general,
 * legal, people, procurement, finance, operations. A module that wants a
 * kind of its own — say, a "vip_agreement" that Estate wants to file as a
 * document without Documents ever hearing the word "estate" — registers it
 * here, once, in its own service provider's boot():
 *
 *     DocumentTypeRegistry::register('vip_agreement', 'VIP agreement', 'Estate');
 *
 * Static rather than a bound singleton, on purpose: DocumentKinds itself is
 * a static utility with no constructor and no container dependency, called
 * from views and scopes that have no reason to resolve anything from the
 * container first. A registry that needed `app(DocumentTypeRegistry::class)`
 * bound as a singleton would need that binding declared in
 * AppServiceProvider, which is exactly the kind of central file a new kind
 * should never have to touch.
 *
 * A built-in key always wins on collision — see DocumentKinds::all() — so a
 * module registering "contract" cannot silently reassign what the built-in
 * catalogue already means by it.
 */
class DocumentTypeRegistry
{
    /** @var array<string, array{label: string, group: string}> */
    protected static array $kinds = [];

    public static function register(string $key, string $label, string $group): void
    {
        self::$kinds[$key] = ['label' => $label, 'group' => $group];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$kinds);
    }

    /** @return array<string, array{label: string, group: string}> */
    public static function all(): array
    {
        return self::$kinds;
    }

    /** Test isolation: RefreshDatabase resets rows, not this class's statics. */
    public static function flush(): void
    {
        self::$kinds = [];
    }
}
