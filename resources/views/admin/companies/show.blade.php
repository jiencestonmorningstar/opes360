<x-layouts.admin :title="$company->name" active="companies">
    <div class="px-5 py-8 lg:px-8">
        <a href="{{ route('admin.companies') }}" class="focusable inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand hover:underline">
            <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
            All companies
        </a>

        @if (session('status'))
            <div class="mt-4 flex items-center gap-2.5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">
                <x-icon name="check-circle" class="size-[18px] shrink-0" stroke-width="2" />
                {{ session('status') }}
            </div>
        @endif

        @if ($company->trashed())
            <div class="mt-4 flex items-center gap-2.5 rounded-xl bg-surface-2 px-4 py-3 text-[13.5px] font-medium text-ink-2">
                <x-icon name="alert" class="size-[18px] shrink-0 text-faint" stroke-width="2" />
                This business was deleted on {{ $company->deleted_at->format('M j, Y') }}. Read-only — plan and access controls are disabled.
            </div>
        @endif

        <div class="mt-4 flex items-start gap-4">
            <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-surface-2 text-[18px] font-bold text-ink-2">
                {{ strtoupper(substr($company->name, 0, 2)) }}
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h1 class="text-[22px] font-bold tracking-[-0.02em] text-ink">{{ $company->name }}</h1>
                    <span class="shrink-0 rounded-full px-3 py-1 text-[11.5px] font-bold uppercase tracking-wide {{ $company->trashed() ? 'bg-surface-2 text-faint' : ($company->isSuspended() ? 'bg-tint-orange text-warning' : 'bg-tint-green text-positive') }}">
                        {{ $company->trashed() ? 'Deleted' : ($company->isSuspended() ? 'Suspended' : $company->account_type) }}
                    </span>
                </div>
                <p class="mt-1 text-[13.5px] text-muted">{{ $company->email }} · created {{ $company->created_at->format('M j, Y') }}</p>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
            @foreach ([
                ['label' => 'Users', 'value' => $company->users_count, 'icon' => 'users'],
                ['label' => 'Documents', 'value' => $documentCount, 'icon' => 'document'],
                ['label' => 'Forms', 'value' => $formCount, 'icon' => 'clipboard'],
                ['label' => 'Events', 'value' => $eventCount, 'icon' => 'ticket'],
                ['label' => 'Currency', 'value' => $company->currency, 'icon' => 'banknotes'],
            ] as $tile)
                <div class="card p-4">
                    <x-icon :name="$tile['icon']" class="size-[18px] text-faint" stroke-width="1.8" />
                    <p class="mt-2.5 text-[12px] font-medium text-muted">{{ $tile['label'] }}</p>
                    <p class="tnum mt-0.5 text-[18px] font-bold text-ink">{{ $tile['value'] }}</p>
                </div>
            @endforeach
        </div>

        @unless ($company->trashed())
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div class="card p-5">
                <p class="flex items-center gap-2 text-[15px] font-bold text-ink">
                    <x-icon name="credit-card" class="size-[18px] text-faint" stroke-width="1.8" />
                    Plan
                </p>
                <form method="POST" action="{{ route('admin.companies.plan', $company) }}" class="mt-3 flex gap-2"
                      onsubmit="return confirm('Change {{ $company->name }}\'s plan?')">
                    @csrf
                    <select name="plan" class="h-11 flex-1 rounded-lg border border-border bg-surface px-3 text-[14px] text-ink">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan }}" @selected($company->plan === $plan)>{{ ucfirst($plan) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="tap focusable h-11 shrink-0 rounded-lg bg-ink px-4 text-[13.5px] font-semibold text-white">Save</button>
                </form>
                <p class="mt-2 text-[12px] text-faint">Only enforced while the account is Active — demo and trial always see every module.</p>
            </div>

            <div class="card p-5">
                <p class="flex items-center gap-2 text-[15px] font-bold text-ink">
                    <x-icon name="alert" class="size-[18px] text-faint" stroke-width="1.8" />
                    Access
                </p>
                @if ($company->isSuspended())
                    <form method="POST" action="{{ route('admin.companies.activate', $company) }}" class="mt-3"
                          onsubmit="return confirm('Reactivate {{ $company->name }}?')">
                        @csrf
                        <button type="submit" class="tap focusable flex h-11 w-full items-center justify-center rounded-lg bg-brand text-[13.5px] font-semibold text-white">
                            Reactivate this business
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.companies.suspend', $company) }}" class="mt-3 space-y-2"
                          onsubmit="return confirm('Suspend {{ $company->name }}? Every user there will be locked out immediately.')">
                        @csrf
                        <textarea name="reason" rows="2" placeholder="Reason (optional, for other admins — not shown to the business)"
                                  class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                        <button type="submit" class="tap focusable flex h-11 w-full items-center justify-center rounded-lg border border-warning/40 bg-tint-orange text-[13.5px] font-semibold text-warning">
                            Suspend this business
                        </button>
                    </form>
                @endif
                <p class="mt-2 text-[12px] text-faint">Suspending locks every user out immediately; nothing is deleted.</p>
            </div>
        </div>
        @endunless

        <div class="mt-5 card overflow-hidden p-0">
            <p class="flex items-center gap-2 border-b border-border px-5 py-3.5 text-[15px] font-bold text-ink">
                <x-icon name="users" class="size-[18px] text-faint" stroke-width="1.8" />
                Members
            </p>
            <div class="divide-y divide-border">
                @foreach ($members as $member)
                    <div class="flex items-center gap-3 px-5 py-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-2 text-[12px] font-semibold text-ink-2">
                            {{ strtoupper(substr($member->name, 0, 1)) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-semibold text-ink">
                                {{ $member->name }}
                                @if ($member->id === $company->owner_id)
                                    <span class="ml-1 text-[11px] font-bold uppercase tracking-wide text-faint">Owner</span>
                                @endif
                            </span>
                            <span class="block text-[12.5px] text-muted">{{ $member->email }} · {{ $member->pivot->job_title ?? '—' }}</span>
                        </span>
                        <span class="shrink-0 text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $member->pivot->status }}</span>

                        @unless ($company->trashed())
                            <div class="relative shrink-0" x-data="{ menu: false }" @click.outside="menu = false">
                                <button type="button" @click="menu = ! menu" class="focusable flex size-8 items-center justify-center rounded-lg text-faint hover:bg-surface-2 hover:text-ink-2" aria-label="Member actions">
                                    <x-icon name="ellipsis" class="size-[18px]" stroke-width="2" />
                                </button>
                                <div x-cloak x-show="menu" x-transition.origin.top.right
                                     class="card absolute right-0 top-[calc(100%+0.25rem)] z-20 w-52 overflow-hidden p-1.5 shadow-[var(--shadow-raised)]">
                                    <form method="POST" action="{{ route('admin.companies.members.reset-password', [$company, $member]) }}"
                                          onsubmit="return confirm('Send a password reset link to {{ $member->email }}?')">
                                        @csrf
                                        <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-[13.5px] font-medium text-ink-2 hover:bg-surface-2">
                                            <x-icon name="cog" class="size-4 text-muted" />
                                            Send password reset
                                        </button>
                                    </form>
                                    @if ($member->id !== $company->owner_id)
                                        <form method="POST" action="{{ route('admin.companies.members.remove', [$company, $member]) }}"
                                              onsubmit="return confirm('Remove {{ $member->name }} from {{ $company->name }}?')">
                                            @csrf
                                            <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-[13.5px] font-medium text-warning hover:bg-tint-orange">
                                                <x-icon name="alert" class="size-4" />
                                                Remove from business
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endunless
                    </div>
                @endforeach
            </div>
        </div>

        @if ($recentDocuments->isNotEmpty())
            <div class="mt-5 card overflow-hidden p-0">
                <p class="flex items-center gap-2 border-b border-border px-5 py-3.5 text-[15px] font-bold text-ink">
                    <x-icon name="document" class="size-[18px] text-faint" stroke-width="1.8" />
                    Recent documents
                </p>
                <div class="divide-y divide-border">
                    @foreach ($recentDocuments as $document)
                        <div class="flex items-center gap-3 px-5 py-3">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-semibold text-ink">{{ $document->number }} · {{ \Illuminate\Support\Str::headline($document->type->value) }}</span>
                                <span class="block text-[12.5px] text-muted">{{ $document->contact?->displayName() ?? 'No customer' }} · {{ $document->issue_date?->format('M j, Y') }}</span>
                            </span>
                            <span class="tnum shrink-0 text-[13.5px] font-semibold text-ink">{{ \App\Support\Money::format($document->total, $document->currency) }}</span>
                            <span class="shrink-0 text-[11px] font-bold uppercase tracking-wide text-faint">{{ \Illuminate\Support\Str::headline($document->status->value) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($recentActivity->isNotEmpty())
            <div class="mt-5 card overflow-hidden p-0">
                <p class="flex items-center gap-2 border-b border-border px-5 py-3.5 text-[15px] font-bold text-ink">
                    <x-icon name="clock" class="size-[18px] text-faint" stroke-width="1.8" />
                    Admin activity on this account
                </p>
                <div class="divide-y divide-border">
                    @foreach ($recentActivity as $entry)
                        <div class="px-5 py-3 text-[13.5px] text-ink-2">
                            <span class="font-semibold">{{ $entry->admin?->name ?? 'Unknown admin' }}</span>
                            {{ str_replace('_', ' ', $entry->action) }}
                            <span class="text-faint">· {{ $entry->created_at->diffForHumans() }}</span>
                            @if ($entry->ip_address)
                                <span class="text-faint">· {{ $entry->ip_address }}</span>
                            @endif
                            @if (! empty($entry->meta['reason']))
                                <span class="mt-1 block text-[12.5px] text-muted">"{{ $entry->meta['reason'] }}"</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.admin>
