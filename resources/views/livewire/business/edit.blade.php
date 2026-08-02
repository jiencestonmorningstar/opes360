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
               class="tap focusable flex items-center gap-2 rounded-full bg-fill-brand px-5 text-[14px] font-semibold text-white hover:opacity-90">
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

    @include('partials.business-tabs')

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
                        <span class="{{ $labelClass }}">RCCM / Registration no.</span>
                        <input type="text" wire:model="form.registration_number" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">NIU / Tax ID</span>
                        <input type="text" wire:model="form.tax_id" class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">VAT number</span>
                        <input type="text" wire:model="form.vat_number" class="{{ $inputClass }}">
                    </label>
                </div>

                <div class="mt-4 grid gap-4 min-[560px]:grid-cols-3">
                    <label>
                        <span class="{{ $labelClass }}">Tax regime</span>
                        <select wire:model.live="form.tax_regime" class="{{ $inputClass }}">
                            <option value="">Not set</option>
                            @foreach (\App\Enums\TaxRegime::all() as $regime)
                                <option value="{{ $regime->value }}">{{ $regime->label() }} — {{ $regime->turnoverBand() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Tax centre</span>
                        <input type="text" wire:model="form.tax_centre" placeholder="Centre des impôts" class="{{ $inputClass }}">
                        @error('form.tax_centre') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>
                    <label>
                        <span class="{{ $labelClass }}">Share capital</span>
                        <input type="number" step="any" min="0" inputmode="decimal" wire:model="form.capital_social" class="{{ $inputClass }}">
                        @error('form.capital_social') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>
                </div>

                {{-- Payroll registration. The two classifications below change
                     what the business pays on top of every salary without
                     changing a single payslip's net, which is exactly why they
                     belong to the business rather than to a shared config. --}}
                @can('payroll.view')
                    <div class="mt-4 grid gap-4 min-[560px]:grid-cols-3">
                        <label>
                            <span class="{{ $labelClass }}">CNPS employer number</span>
                            <input type="text" wire:model="form.cnps_employer_number" class="{{ $inputClass }}">
                            @error('form.cnps_employer_number') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                        </label>
                        <label>
                            <span class="{{ $labelClass }}">Occupational risk group</span>
                            <select wire:model="form.cnps_risk_group" class="{{ $inputClass }}">
                                @foreach (config('payroll.cnps.occupational_risk.groups') as $key => $group)
                                    <option value="{{ $key }}">{{ $group['label'] }}</option>
                                @endforeach
                            </select>
                            @error('form.cnps_risk_group') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                        </label>
                        <label>
                            <span class="{{ $labelClass }}">Family allowance regime</span>
                            <select wire:model="form.cnps_family_regime" class="{{ $inputClass }}">
                                <option value="general">General — 7%</option>
                                <option value="agricultural">Agricultural — 5.65%</option>
                                <option value="teaching">Teaching &amp; domestic — 3.7%</option>
                            </select>
                            @error('form.cnps_family_regime') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                        </label>
                    </div>

                    {{-- Both are withheld from the employee and paid on to a
                         council or the CRTV. Switchable because withholding a
                         figure the business cannot justify to the person it
                         came from is worse than not withholding it — see the
                         provenance note in config/payroll.php. --}}
                    <div class="mt-4 rounded-xl border border-border p-4">
                        <p class="text-[14px] font-semibold text-ink">Payroll withholdings</p>
                        <p class="mt-1 text-[12.5px] leading-relaxed text-muted">
                            Both come off the employee's payslip and are paid on by you. Switch one off only if your
                            accountant says it does not apply — the payslip will stop showing it from the next run.
                        </p>

                        <div class="mt-3 flex flex-col gap-3">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="form.withhold_tdl"
                                       class="mt-0.5 size-[20px] shrink-0 rounded border-border text-brand focus:ring-brand/30">
                                <span>
                                    <span class="block text-[14px] font-semibold text-ink">Taxe de développement local</span>
                                    <span class="block text-[12.5px] text-muted">
                                        A fixed amount by salary band, paid to the council where the business sits.
                                        Nothing is due below {{ number_format(config('payroll.tdl.floor')) }} F.
                                    </span>
                                </span>
                            </label>

                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model.live="form.withhold_rav"
                                       class="mt-0.5 size-[20px] shrink-0 rounded border-border text-brand focus:ring-brand/30">
                                <span>
                                    <span class="block text-[14px] font-semibold text-ink">Redevance audiovisuelle</span>
                                    <span class="block text-[12.5px] text-muted">
                                        For the CRTV, by band on the gross taxable salary, under ordonnance 89/004.
                                        Nothing is due below {{ number_format(config('payroll.rav.floor')) }} F.
                                    </span>
                                </span>
                            </label>
                        </div>

                        {{-- The scale itself, on screen.
                             Its legal instrument, its floor, its structure and
                             its base are all confirmed; the amount in each band
                             comes from a secondary source. Leaving that in a
                             config comment meant the one person who could check
                             it — an accountant — would never see it. Ten seconds
                             of their time here is worth more than any amount of
                             hedging in the code. --}}
                        @if ($form['withhold_rav'])
                            <details class="mt-4 rounded-xl bg-surface-2 p-4" @if (! $form['rav_confirmed']) open @endif>
                                <summary class="focusable cursor-pointer text-[13px] font-semibold text-ink-2">
                                    The bands this uses
                                    @unless ($form['rav_confirmed'])
                                        <span class="ml-1 font-medium text-warning">— not yet checked</span>
                                    @endunless
                                </summary>

                                <table class="tnum mt-3 w-full text-[12.5px]">
                                    <thead>
                                        <tr class="text-left text-muted">
                                            <th class="pb-1.5 font-medium">Gross taxable salary</th>
                                            <th class="pb-1.5 text-right font-medium">Per month</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @php $from = config('payroll.rav.floor'); @endphp
                                        @foreach (config('payroll.rav.bands') as $band)
                                            <tr class="border-t border-border">
                                                <td class="py-1.5 text-ink-2">
                                                    {{ number_format($from) }}
                                                    @if ($band['upto']) – {{ number_format($band['upto']) }} @else and above @endif
                                                </td>
                                                <td class="py-1.5 text-right font-semibold text-ink">{{ number_format($band['amount']) }} F</td>
                                            </tr>
                                            @php $from = $band['upto'] ? $band['upto'] + 1 : $from; @endphp
                                        @endforeach
                                    </tbody>
                                </table>

                                <label class="mt-4 flex items-start gap-3">
                                    <input type="checkbox" wire:model="form.rav_confirmed"
                                           class="mt-0.5 size-[20px] shrink-0 rounded border-border text-brand focus:ring-brand/30">
                                    <span class="text-[12.5px] leading-relaxed text-ink-2">
                                        These figures have been checked against the current schedule.
                                        <span class="block text-muted">
                                            Until this is ticked, every payroll run says the scale is unverified.
                                            Correct any band in <span class="tnum">config/payroll.php</span>.
                                        </span>
                                    </span>
                                </label>
                            </details>
                        @endif
                    </div>
                @endcan

                {{-- VAT. Only the régime du réel collects it, so the toggle is
                     kept separate from the regime rather than derived from it —
                     a business can sit between regimes or hold an exemption,
                     and invoicing VAT you are not registered for is an offence. --}}
                <div class="mt-4 rounded-xl border border-border p-4">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model.live="form.vat_registered"
                               class="mt-0.5 size-[20px] shrink-0 rounded border-border text-brand focus:ring-brand/30">
                        <span>
                            <span class="block text-[14px] font-semibold text-ink">This business is registered for VAT</span>
                            <span class="block text-[12.5px] text-muted">
                                In Cameroon only the régime du réel collects TVA, above 50M FCFA turnover.
                                Leave this off and documents carry a “TVA non applicable” mention instead.
                            </span>
                        </span>
                    </label>

                    @if ($form['vat_registered'] ?? false)
                        <div class="mt-4 grid gap-4 min-[560px]:grid-cols-2">
                            <label>
                                <span class="{{ $labelClass }}">VAT rate (%)</span>
                                <input type="number" step="any" min="0" max="100" inputmode="decimal"
                                       wire:model="form.vat_rate" class="{{ $inputClass }}">
                                <span class="mt-1 block text-[12px] text-muted">Cameroon: 19.25 (17.5% TVA + 10% CAC).</span>
                                @error('form.vat_rate') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                            </label>
                            <label class="flex items-start gap-3 pt-6">
                                <input type="checkbox" wire:model="form.prices_include_tax"
                                       class="mt-0.5 size-[20px] shrink-0 rounded border-border text-brand focus:ring-brand/30">
                                <span>
                                    <span class="block text-[14px] font-semibold text-ink">Prices already include VAT</span>
                                    <span class="block text-[12.5px] text-muted">
                                        Tick this if you key the shelf price. VAT is then taken out of it
                                        rather than added on top.
                                    </span>
                                </span>
                            </label>
                        </div>
                    @endif
                </div>

                {{-- Which revenue account a sale lands in. Most invoice lines are
                     typed freehand rather than picked from the catalogue, so
                     there is nothing on the line to tell goods from services —
                     without this a consultancy books all its income as the sale
                     of goods it never sold. Lines that *do* point at a catalogue
                     item follow that item and ignore this. --}}
                <label class="mt-4 block">
                    <span class="{{ $labelClass }}">What this business mainly sells</span>
                    <select wire:model="form.default_sales_account" class="{{ $inputClass }}">
                        <option value="sales_goods">Goods — revenue posts to 701 Ventes de marchandises</option>
                        <option value="sales_services">Services — revenue posts to 706 Services vendus</option>
                    </select>
                    <span class="mt-1 block text-[12px] text-muted">
                        Used for invoice lines typed by hand. Lines added from your product list
                        follow the product's own type.
                    </span>
                    @error('form.default_sales_account') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>

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
                    class="focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90 lg:w-auto lg:px-10">
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

            {{-- Public reviews: moderation entry (gated like the screen itself) --}}
            @can('business.update')
                <x-ui.panel title="Reviews">
                    <a href="{{ route('business.reviews') }}"
                       class="focusable flex items-center justify-between gap-3 rounded-xl bg-surface-2 px-4 py-3 hover:opacity-90">
                        <span>
                            <span class="block text-[14px] font-semibold text-ink">Moderate reviews</span>
                            <span class="block text-[12px] text-muted">Approve what shows on your public page.</span>
                        </span>
                        @if ($pendingReviews > 0)
                            <span class="tnum shrink-0 rounded-full bg-tint-orange px-3 py-1 text-[12.5px] font-bold text-warning">
                                {{ $pendingReviews }} pending
                            </span>
                        @else
                            <x-icon name="chevron-right" class="size-[16px] shrink-0 text-muted" stroke-width="2.2" />
                        @endif
                    </a>
                </x-ui.panel>
            @endcan

            {{-- Loyalty (start) --}}
            @can('loyalty.manage')
                <x-ui.panel title="Loyalty program">
                    @if (session('loyaltyStatus'))
                        <div class="mb-4 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
                            {{ session('loyaltyStatus') }}
                        </div>
                    @endif

                    <label class="flex items-center justify-between gap-3">
                        <span>
                            <span class="block text-[14px] font-semibold text-ink">Points on every payment</span>
                            <span class="block text-[12px] text-muted">Customers earn points automatically when a payment is recorded against them.</span>
                        </span>
                        <input type="checkbox" wire:model="loyaltyEnabled"
                               class="size-[20px] shrink-0 rounded border-border-strong text-brand focus:ring-brand/30">
                    </label>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div>
                            <label for="loyalty-spend-per-point" class="mb-1 block text-[12.5px] font-semibold text-ink-2">Spend per point</label>
                            <input id="loyalty-spend-per-point" type="number" step="0.01" min="0.01" wire:model="loyaltyPointsPerAmount"
                                   class="h-11 w-full rounded-lg border border-border bg-surface px-3 text-[14px] text-ink focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand/20">
                            @error('loyaltyPointsPerAmount')<p class="mt-1 text-[12px] font-medium text-warning">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="loyalty-point-value" class="mb-1 block text-[12.5px] font-semibold text-ink-2">Value per point</label>
                            <input id="loyalty-point-value" type="number" step="0.01" min="0" wire:model="loyaltyPointValue"
                                   class="h-11 w-full rounded-lg border border-border bg-surface px-3 text-[14px] text-ink focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand/20">
                            @error('loyaltyPointValue')<p class="mt-1 text-[12px] font-medium text-warning">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <button type="button" wire:click="saveLoyaltySettings"
                            class="tap focusable mt-4 flex h-11 w-full items-center justify-center rounded-xl bg-fill-brand text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
                        Save loyalty settings
                    </button>
                </x-ui.panel>
            @endcan
            {{-- Loyalty (end) --}}

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
