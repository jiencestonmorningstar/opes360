<x-layouts.public :title="$vacancy->title().' · '.$company->name" :brand-company="$company" robots="noindex" width="max-w-[560px]">
    <div class="card border-t-4 border-t-brand p-6">
        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $company->name }} is hiring</p>
        <h1 class="mt-1.5 text-[24px] font-bold leading-tight tracking-[-0.02em] text-ink">{{ $vacancy->title() }}</h1>
        @if ($vacancy->description)
            <p class="mt-2 whitespace-pre-line text-[14.5px] leading-relaxed text-muted">{{ $vacancy->description }}</p>
        @endif
    </div>

    @if (! $vacancy->isOpen())
        <div class="card mt-4 px-6 py-10 text-center">
            <p class="text-[16px] font-semibold text-ink">This vacancy is not accepting applications.</p>
            <p class="mt-1.5 text-[13.5px] text-muted">If you were expecting to apply, contact {{ $company->name }}.</p>
        </div>
    @else
        @if ($errors->any())
            <div class="card mt-4 border-warning/40 bg-tint-orange px-5 py-4">
                <p class="text-[13.5px] font-semibold text-warning">Check the highlighted answers below.</p>
            </div>
        @endif

        <form method="POST" action="{{ url('/jobs/'.$vacancy->share_token) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
            @csrf

            <div class="card space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-[15px] font-semibold text-ink">First name <span class="text-warning">*</span></label>
                        <input type="text" name="first_name" value="{{ old('first_name') }}" required
                               class="mt-2 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @error('first_name') <p class="mt-1 text-[13px] text-warning">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[15px] font-semibold text-ink">Last name <span class="text-warning">*</span></label>
                        <input type="text" name="last_name" value="{{ old('last_name') }}" required
                               class="mt-2 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @error('last_name') <p class="mt-1 text-[13px] text-warning">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-[15px] font-semibold text-ink">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}"
                               class="mt-2 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @error('email') <p class="mt-1 text-[13px] text-warning">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[15px] font-semibold text-ink">Phone</label>
                        <input type="text" name="phone" value="{{ old('phone') }}"
                               class="mt-2 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @error('phone') <p class="mt-1 text-[13px] text-warning">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-[15px] font-semibold text-ink">Where did you hear about this job?</label>
                    <input type="text" name="source" value="{{ old('source') }}"
                           class="mt-2 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                </div>

                <div>
                    <label class="block text-[15px] font-semibold text-ink">A few words about yourself</label>
                    <textarea name="cover_note" rows="4"
                              class="mt-2 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">{{ old('cover_note') }}</textarea>
                    @error('cover_note') <p class="mt-1 text-[13px] text-warning">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-[15px] font-semibold text-ink">Your CV</label>
                    <p class="mt-0.5 text-[13px] text-muted">PDF or Word document, up to 10 MB.</p>
                    <input type="file" name="cv" accept=".pdf,.doc,.docx,.odt,.txt,.png,.jpg,.jpeg"
                           class="mt-2 w-full text-[14px] text-ink file:mr-3 file:rounded-full file:border-0 file:bg-brand/10 file:px-4 file:py-2 file:text-[13px] file:font-semibold file:text-brand">
                    @error('cv') <p class="mt-1 text-[13px] text-warning">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit"
                    class="flex h-12 w-full items-center justify-center rounded-full bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                Send my application
            </button>
        </form>
    @endif
</x-layouts.public>
