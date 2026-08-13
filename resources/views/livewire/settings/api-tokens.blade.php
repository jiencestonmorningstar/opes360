<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="mx-auto max-w-3xl">

        <a href="{{ route('settings') }}" wire:navigate
           class="focusable -ml-1 inline-flex items-center gap-1.5 rounded-lg p-1 text-[14px] font-semibold text-muted hover:text-ink">
            <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
            Settings
        </a>

        <h1 class="mt-3 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">API tokens</h1>
        <p class="mt-1 text-[14.5px] leading-relaxed text-muted">
            A token lets another program act on your business through the {{ config('opes.brand.name') }} API.
            Give each one only what it needs — you can revoke any of them here without changing your password.
        </p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
                {{ session('status') }}
            </div>
        @endif

        {{-- Shown once. Storing it anywhere retrievable would make hashing it
             pointless, so the only chance to copy it is now. --}}
        @if ($plainTextToken)
            <div class="card mt-5 border-brand p-5 ring-1 ring-brand">
                <p class="text-[14.5px] font-bold text-ink">Copy this now — it will not be shown again</p>
                <p class="mt-1 text-[13px] leading-relaxed text-muted">
                    We keep only a hash of it, so if you lose it you will need to create another.
                </p>

                <div class="mt-3 flex items-center gap-2">
                    <code class="min-w-0 flex-1 overflow-x-auto rounded-lg border border-border bg-surface-2 px-3 py-2.5 text-[12.5px] text-ink">{{ $plainTextToken }}</code>
                    <button type="button"
                            x-data="{ copied: false }"
                            @click="navigator.clipboard.writeText(@js($plainTextToken)); copied = true; setTimeout(() => copied = false, 2000)"
                            class="tap focusable flex h-11 shrink-0 items-center rounded-xl bg-fill-brand px-4 text-[13.5px] font-semibold text-white">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>

                <button type="button" wire:click="dismissToken"
                        class="focusable mt-3 text-[13px] font-semibold text-muted hover:text-ink">
                    I have copied it
                </button>
            </div>
        @endif

        <div class="card mt-5 p-5">
            <h2 class="text-[15.5px] font-bold text-ink">Create a token</h2>

            <form wire:submit="create" class="mt-4 space-y-4">
                <div>
                    <label for="token-name" class="block text-[13.5px] font-semibold text-ink-2">What is it for?</label>
                    <input id="token-name" type="text" wire:model="name" autocomplete="off"
                           placeholder="Stock sync with the warehouse system"
                           class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('name') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <span class="block text-[13.5px] font-semibold text-ink-2">What may it do?</span>
                    <p class="mt-1 text-[12.5px] text-muted">
                        A token can never do more than you can. These only narrow it further.
                    </p>

                    <div class="mt-2.5 space-y-2">
                        @foreach ($catalogue as $key => $label)
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-surface p-3.5">
                                <input type="checkbox" wire:model="abilities" value="{{ $key }}"
                                       class="mt-0.5 size-4 shrink-0 rounded border-border-strong text-brand focus:ring-brand/30">
                                <span class="min-w-0">
                                    <span class="block text-[14px] font-semibold text-ink">{{ $label }}</span>
                                    <span class="block text-[12.5px] text-muted">
                                        @switch($key)
                                            @case('read') Lists and single records, across the business. @break
                                            @case('write') Add and change customers, products, deals and documents. @break
                                            @case('money') Record payments, and enter or settle expenses. @break
                                            @case('people') Read the staff file and payroll figures. @break
                                        @endswitch
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('abilities') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <button type="submit" wire:loading.attr="disabled" wire:target="create"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                    <span wire:loading.remove wire:target="create">Create token</span>
                    <span wire:loading wire:target="create">Creating…</span>
                </button>
            </form>
        </div>

        <div class="card mt-5 p-5">
            <h2 class="text-[15.5px] font-bold text-ink">Your tokens</h2>

            @forelse ($tokens as $token)
                <div wire:key="token-{{ $token->id }}"
                     class="mt-3 flex items-start justify-between gap-4 rounded-xl border border-border p-3.5">
                    <div class="min-w-0">
                        <p class="truncate text-[14px] font-semibold text-ink">{{ $token->name }}</p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            {{ in_array('*', $token->abilities ?? [], true)
                                ? 'Full access'
                                : implode(', ', array_map(fn ($a) => $catalogue[$a] ?? $a, $token->abilities ?? [])) }}
                        </p>
                        <p class="mt-1 text-[11.5px] text-faint">
                            Created {{ $token->created_at?->diffForHumans() }}
                            @if ($token->last_used_at)
                                &middot; last used {{ $token->last_used_at->diffForHumans() }}
                            @else
                                &middot; never used
                            @endif
                        </p>
                    </div>

                    <button type="button" wire:click="revoke('{{ $token->id }}')"
                            wire:confirm="Revoke this token? Anything using it will stop working immediately."
                            class="tap focusable shrink-0 rounded-lg px-3 text-[13px] font-semibold text-negative hover:bg-tint-red">
                        Revoke
                    </button>
                </div>
            @empty
                <p class="mt-3 text-[13.5px] text-muted">
                    You have no tokens yet. Anything you create will be listed here.
                </p>
            @endforelse
        </div>

        <p class="mt-5 text-[13px] leading-relaxed text-muted">
            Sending a token: add the header
            <code class="rounded bg-surface-2 px-1.5 py-0.5 text-[12px] text-ink-2">Authorization: Bearer &lt;token&gt;</code>
            to your request. The API lives at
            <code class="rounded bg-surface-2 px-1.5 py-0.5 text-[12px] text-ink-2">{{ url('/api/v1') }}</code>.
        </p>
    </div>
</div>
