@php
    /*
     * This view renders twice over: standalone behind the share link, and
     * inside an <iframe> on someone else's website (the /embed routes). The
     * embedded run cannot rely on the session — third-party cookie rules
     * strip it — so errors and old input arrive as plain variables from the
     * controller instead of session flashes.
     */
    $embed = $embed ?? false;
    $action = $embed ? route('form.embed.submit', $form->share_token) : route('form.submit', $form->share_token);
@endphp

<x-layouts.public :title="$form->title.' · '.$company->name" robots="noindex" width="max-w-[560px]">
    <div class="card border-t-4 border-t-brand p-6">
        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $company->name }}</p>
        <h1 class="mt-1.5 text-[24px] font-bold leading-tight tracking-[-0.02em] text-ink">{{ $form->title }}</h1>
        @if ($form->description)
            <p class="mt-2 text-[14.5px] leading-relaxed text-muted">{{ $form->description }}</p>
        @endif
    </div>

    @if (! $form->isOpen())
        <div class="card mt-4 px-6 py-10 text-center">
            <p class="text-[16px] font-semibold text-ink">This form is not accepting responses.</p>
            <p class="mt-1.5 text-[13.5px] text-muted">If you were expecting to fill it in, contact {{ $company->name }}.</p>
        </div>
    @else
        @if ($errors->any())
            <div class="card mt-4 border-warning/40 bg-tint-orange px-5 py-4">
                <p class="text-[13.5px] font-semibold text-warning">Check the highlighted answers below.</p>
            </div>
        @endif

        <form method="POST" action="{{ $action }}" class="mt-4 space-y-4">
            @if (! $embed)
                @csrf
            @endif

            @foreach ($fields as $field)
                @php
                    $key = 'answers.'.$field['id'];
                    $name = "answers[{$field['id']}]";
                    $old = $embed ? data_get($oldInput ?? [], $field['id']) : old('answers.'.$field['id']);
                @endphp

                <div class="card p-5 {{ $errors->has($key) || $errors->has($key.'.*') ? 'border-warning/60' : '' }}">
                    <label class="block text-[15px] font-semibold text-ink">
                        {{ $field['label'] ?: 'Untitled' }}
                        @if ($field['required'])<span class="text-warning">*</span>@endif
                    </label>
                    @if ($field['help'])
                        <p class="mt-0.5 text-[13px] text-muted">{{ $field['help'] }}</p>
                    @endif

                    <div class="mt-3">
                        @switch($field['type'])
                            @case('long_text')
                                <textarea name="{{ $name }}" rows="4" @required($field['required'])
                                          class="w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">{{ $old }}</textarea>
                                @break

                            @case('choice')
                                <div class="space-y-2.5">
                                    @foreach ($field['options'] as $option)
                                        <label class="flex items-center gap-3 text-[14.5px] text-ink">
                                            <input type="radio" name="{{ $name }}" value="{{ $option }}"
                                                   @checked($old === $option) @required($field['required'])
                                                   class="size-[18px] border-border-strong text-brand focus:ring-brand/30">
                                            {{ $option }}
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case('checkboxes')
                                <div class="space-y-2.5">
                                    @foreach ($field['options'] as $option)
                                        <label class="flex items-center gap-3 text-[14.5px] text-ink">
                                            <input type="checkbox" name="{{ $name }}[]" value="{{ $option }}"
                                                   @checked(is_array($old) && in_array($option, $old, true))
                                                   class="size-[18px] rounded border-border-strong text-brand focus:ring-brand/30">
                                            {{ $option }}
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case('dropdown')
                                <select name="{{ $name }}" @required($field['required'])
                                        class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                    <option value="">Choose…</option>
                                    @foreach ($field['options'] as $option)
                                        <option value="{{ $option }}" @selected($old === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                                @break

                            @case('date')
                                <input type="date" name="{{ $name }}" value="{{ $old }}" @required($field['required'])
                                       class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                @break

                            @case('number')
                                <input type="number" name="{{ $name }}" value="{{ $old }}" step="any" @required($field['required'])
                                       class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                @break

                            @case('email')
                                <input type="email" name="{{ $name }}" value="{{ $old }}" @required($field['required'])
                                       placeholder="you@example.com"
                                       class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                @break

                            @case('phone')
                                <input type="tel" name="{{ $name }}" value="{{ $old }}" @required($field['required'])
                                       class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                @break

                            @default
                                <input type="text" name="{{ $name }}" value="{{ $old }}" @required($field['required'])
                                       class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @endswitch
                    </div>

                    @error($key)
                        <p class="mt-2 text-[13px] font-medium text-warning">{{ $message }}</p>
                    @enderror
                    @error($key.'.*')
                        <p class="mt-2 text-[13px] font-medium text-warning">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <button type="submit"
                    class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Submit
            </button>
        </form>
    @endif

    <p class="{{ $embed ? 'mt-4' : 'mt-7' }} text-center text-[12px] text-faint">
        Powered by <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
        · Never submit passwords through this form.
    </p>
</x-layouts.public>
