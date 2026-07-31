@php $canManage = auth('admin')->user()->isAdmin(); @endphp

<x-layouts.admin title="Admins" active="admins">
    <div class="px-5 py-8 lg:px-8">
        <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Platform admins</h1>
        <p class="mt-1 text-[14px] text-muted">Everyone who can sign in to this panel. Full read access across every business — invite carefully.</p>

        @if (session('status'))
            <div class="mt-4 flex items-center gap-2.5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">
                <x-icon name="check-circle" class="size-[18px] shrink-0" stroke-width="2" />
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($canManage)
            <div class="mt-5 card p-5">
                <p class="text-[15px] font-bold text-ink">Invite an admin</p>
                <form method="POST" action="{{ route('admin.admins.store') }}" class="mt-3 flex flex-wrap gap-2.5">
                    @csrf
                    <input type="text" name="name" placeholder="Name" required value="{{ old('name') }}"
                           class="h-11 min-w-[160px] flex-1 rounded-lg border border-border bg-surface px-3.5 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    <input type="email" name="email" placeholder="Email" required value="{{ old('email') }}"
                           class="h-11 min-w-[200px] flex-1 rounded-lg border border-border bg-surface px-3.5 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    <select name="role" class="h-11 rounded-lg border border-border bg-surface px-3 text-[14px] text-ink">
                        <option value="{{ \App\Models\PlatformAdmin::ROLE_SUPPORT }}">Support</option>
                        <option value="{{ \App\Models\PlatformAdmin::ROLE_ADMIN }}">Admin</option>
                    </select>
                    <button type="submit" class="tap focusable h-11 shrink-0 rounded-lg bg-ink px-5 text-[13.5px] font-semibold text-white">Send invite</button>
                </form>
                <p class="mt-2 text-[12px] text-faint">
                    They'll get an email with a link to set their own password — nothing is ever transmitted here.
                    <strong>Support</strong> can suspend businesses and manage members but not change plans or invite/revoke admins.
                </p>
            </div>
        @else
            <div class="mt-5 rounded-xl bg-surface-2 px-4 py-3 text-[13px] text-muted">
                Inviting and revoking admins needs the full Admin role — you're signed in as Support.
            </div>
        @endif

        <div class="mt-5 card overflow-hidden p-0">
            <div class="divide-y divide-border">
                @foreach ($admins as $admin)
                    <div class="flex items-center gap-3 px-5 py-3.5">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-surface-2 text-[13px] font-semibold text-ink-2">
                            {{ strtoupper(substr($admin->name, 0, 1)) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-semibold text-ink">
                                {{ $admin->name }}
                                @if ($admin->id === auth('admin')->id())
                                    <span class="ml-1 text-[11px] font-bold uppercase tracking-wide text-faint">You</span>
                                @endif
                            </span>
                            <span class="block text-[12.5px] text-muted">{{ $admin->email }}</span>
                        </span>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide {{ $admin->isAdmin() ? 'bg-tint-blue text-accent-blue' : 'bg-surface-2 text-faint' }}">
                            {{ $admin->isAdmin() ? 'Admin' : 'Support' }}
                        </span>
                        <span class="shrink-0 text-[12px] text-faint">Joined {{ $admin->created_at->format('M j, Y') }}</span>

                        @if ($canManage && $admin->id !== auth('admin')->id())
                            <form method="POST" action="{{ route('admin.admins.destroy', $admin) }}"
                                  onsubmit="return confirm('Revoke {{ $admin->email }}\'s admin access?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="focusable shrink-0 text-[12.5px] font-semibold text-warning hover:underline">
                                    Revoke
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.admin>
