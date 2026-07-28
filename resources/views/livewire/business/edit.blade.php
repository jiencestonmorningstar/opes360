@php
    use Illuminate\Support\Facades\Storage;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Business</h1>
            <p class="mt-1 text-[14.5px] text-muted">Your identity on every document you issue.</p>
        </div>

        <div class="flex shrink-0 gap-2">
            <a href="{{ route('stationery') }}"
               class="tap focusable flex items-center gap-2 rounded-full bg-brand px-5 text-[14px] font-semibold text-white hover:opacity-90">
                <x-icon name="printer" class="size-[16px]" stroke-width="2" />
                Stationery
            </a>
            <a href="{{ route('profile.business', $company) }}" target="_blank"
               class="tap focusable hidden items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14px] font-semibold text-ink-2 hover:bg-surface-2 min-[560px]:flex">
                Public page
                <x-icon name="chevron-right" class="size-[15px]" stroke-width="2.4" />
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-semibold text-positive">
            {{ session('status') }}
        </div>
    @endif

    <div class="mt-5 grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">

            <x-ui.panel title="Identity">
                <div class="flex items-center gap-4">
                    @if ($logoUpload)
                        <img src="{{ $logoUpload->temporaryUrl() }}" alt="" class="size-[72px] rounded-2xl object-cover">
                    @elseif ($company->logo_path)
                        <img src="{{ Storage::disk('public')->url($company->logo_path) }}" alt=""
                             class="size-[72px] rounded-2xl object-cover">
                    @else
                        <span class="flex size-[72px] items-center justify-center rounded-2xl bg-tint-blue text-[22px] font-bold text-accent-blue">
                            {{ $company->initials() }}
                        </span>
                    @endif

                    <div>
                        <label class="focusable inline-flex h-10 cursor-pointer items-center rounded-full border border-border bg-surface px-4 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            {{ $company->logo_path || $logoUpload ? 'Change logo' : 'Upload logo' }}
                            <input type="file" wire:model="logoUpload" accept="image/*" class="hidden">
                        </label>
                        <p class="mt-1.5 text-[12px] text-muted">PNG or JPG, up to 4&nbsp;MB. The AI logo generator arrives in Phase&nbsp;2.</p>
                        @error('logoUpload') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-5 grid gap-4 min-[560px]:grid-cols-2">
                    <label class="min-[560px]:col-span-2">
                        <span class="{{ $labelClass }}">Business name</span>
                        <input type="text" wire:model="form.name" class="{{ $inputClass }}">
                        @error('form.name') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Motto</span>
                        <input type="text" wire:model="form.motto" placeholder="Business made simple" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Industry</span>
                        <select wire:model="form.industry" class="{{ $inputClass }}">
                            <option value="">Choose…</option>
                            @foreach (config('opes.industries') as $industry)
                                <option value="{{ $industry }}">{{ $industry }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="min-[560px]:col-span-2">
                        <span class="{{ $labelClass }}">Description</span>
                        <textarea wire:model="form.description" rows="3" placeholder="What does your business do?"
                                  class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                    </label>
                </div>
            </x-ui.panel>

            <x-ui.panel title="Registration & Tax">
                <div class="grid gap-4 min-[560px]:grid-cols-3">
                    <label>
                        <span class="{{ $labelClass }}">Registration no.</span>
                        <input type="text" wire:model="form.registration_number" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Tax ID</span>
                        <input type="text" wire:model="form.tax_id" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">VAT number</span>
                        <input type="text" wire:model="form.vat_number" class="{{ $inputClass }}">
                    </label>
                </div>
                <p class="mt-3 text-[12.5px] text-muted">Tax identifiers are encrypted at rest and never shown on your public page.</p>
            </x-ui.panel>

            <x-ui.panel title="Contact & Location">
                <div class="grid gap-4 min-[560px]:grid-cols-2">
                    <label>
                        <span class="{{ $labelClass }}">Email</span>
                        <input type="email" wire:model="form.email" class="{{ $inputClass }}">
                        @error('form.email') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Website</span>
                        <input type="text" wire:model="form.website" placeholder="https://…" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Phone</span>
                        <input type="tel" wire:model="form.phone1" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Phone 2</span>
                        <input type="tel" wire:model="form.phone2" class="{{ $inputClass }}">
                    </label>
                    <label class="min-[560px]:col-span-2">
                        <span class="{{ $labelClass }}">Street address</span>
                        <input type="text" wire:model="form.address_line1" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">City</span>
                        <input type="text" wire:model="form.city" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Region / State</span>
                        <input type="text" wire:model="form.region" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Country code</span>
                        <input type="text" wire:model="form.country" maxlength="2" placeholder="NG" class="{{ $inputClass }} uppercase">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Currency</span>
                        <select wire:model="form.currency" class="{{ $inputClass }}">
                            @foreach (config('opes.currencies') as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </x-ui.panel>

            <x-ui.panel title="Social Links">
                <div class="grid gap-4 min-[560px]:grid-cols-2">
                    @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X (Twitter)', 'linkedin' => 'LinkedIn', 'whatsapp' => 'WhatsApp number'] as $key => $label)
                        <label>
                            <span class="{{ $labelClass }}">{{ $label }}</span>
                            <input type="text" wire:model="form.{{ $key }}" class="{{ $inputClass }}">
                        </label>
                    @endforeach
                </div>
            </x-ui.panel>

            <button type="button" wire:click="save" wire:loading.attr="disabled"
                    class="focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90 lg:w-auto lg:px-10">
                <span wire:loading.remove wire:target="save">Save Changes</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>

        {{-- Identity sidebar: the permanent business QR --}}
        <div class="space-y-4">
            <x-ui.panel title="Business QR">
                <div class="flex flex-col items-center text-center">
                    <img src="{{ route('verification.qr', $businessToken->token) }}" alt="Business QR code"
                         class="size-[168px] rounded-xl bg-white p-2">
                    <p class="mt-3 text-[13px] text-muted">
                        Print this on cards and stationery. Scanning opens your verified public page.
                    </p>
                    <p class="mt-2 break-all text-[12.5px] font-semibold text-ink">
                        {{ url('/business/'.$company->slug) }}
                    </p>
                </div>
            </x-ui.panel>

            <x-ui.panel title="Verification">
                <div class="flex items-center gap-3">
                    <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-tint-green">
                        <x-icon name="check-circle" class="size-[22px] text-accent-green" />
                    </span>
                    <div>
                        <p class="text-[14px] font-semibold text-positive">Verified business</p>
                        <p class="text-[12px] text-muted">Documents you issue carry this identity.</p>
                    </div>
                </div>
            </x-ui.panel>
        </div>
    </div>
</div>
