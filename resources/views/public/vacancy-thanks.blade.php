<x-layouts.public :title="'Application received · '.$company->name" :brand-company="$company" robots="noindex" width="max-w-[560px]">
    <div class="card px-6 py-12 text-center">
        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $company->name }}</p>
        <h1 class="mt-2 text-[24px] font-bold leading-tight tracking-[-0.02em] text-ink">Application received</h1>
        <p class="mx-auto mt-3 max-w-[400px] text-[14.5px] leading-relaxed text-muted">
            Thank you for applying for <strong>{{ $vacancy->title() }}</strong>.
            {{ $company->name }} will be in touch if your application moves forward.
        </p>
    </div>
</x-layouts.public>
