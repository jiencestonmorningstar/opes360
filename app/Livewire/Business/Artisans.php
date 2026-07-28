<?php

namespace App\Livewire\Business;

use App\Models\Artisan;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Module 5 — artisan directory.
 *
 * Each artisan gets a permanent public profile and its own verification token,
 * reusing the same identity infrastructure as businesses and documents.
 */
class Artisans extends Component
{
    use WithFileUploads;

    public bool $editing = false;

    public ?string $editingId = null;

    public $photo = null;

    /** @var array<string, mixed> */
    public array $form = [
        'full_name' => '',
        'occupation' => '',
        'trade_category' => '',
        'skills' => '',
        'biography' => '',
        'years_experience' => '',
        'phone' => '',
        'whatsapp' => '',
        'email' => '',
        'city' => '',
        'coverage' => '',
        'languages' => '',
        'is_published' => true,
    ];

    public function startCreating(): void
    {
        $this->reset('form', 'editingId', 'photo');
        $this->editing = true;
    }

    public function edit(string $id): void
    {
        $artisan = Artisan::findOrFail($id);

        $this->editingId = $artisan->id;
        $this->editing = true;
        $this->form = [
            'full_name' => $artisan->full_name,
            'occupation' => $artisan->occupation,
            'trade_category' => $artisan->trade_category,
            'skills' => implode(', ', $artisan->skills ?? []),
            'biography' => $artisan->biography,
            'years_experience' => $artisan->years_experience !== null ? (string) $artisan->years_experience : '',
            'phone' => data_get($artisan->phones, 0),
            'whatsapp' => $artisan->whatsapp,
            'email' => $artisan->email,
            'city' => data_get($artisan->address, 'city'),
            'coverage' => implode(', ', $artisan->coverage_area ?? []),
            'languages' => implode(', ', $artisan->languages ?? []),
            'is_published' => $artisan->is_published,
        ];
    }

    public function cancel(): void
    {
        $this->reset('editing', 'editingId', 'form', 'photo');
    }

    public function save(): void
    {
        $this->validate([
            'form.full_name' => ['required', 'string', 'max:120'],
            'form.occupation' => ['nullable', 'string', 'max:80'],
            'form.email' => ['nullable', 'email', 'max:160'],
            'form.years_experience' => ['nullable', 'integer', 'min:0', 'max:80'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ], [
            'form.full_name.required' => 'The artisan needs a name.',
        ]);

        $company = app(CurrentCompany::class)->get();

        $attributes = [
            'full_name' => trim($this->form['full_name']),
            'occupation' => $this->form['occupation'] ?: null,
            'trade_category' => $this->form['trade_category'] ?: null,
            'skills' => $this->splitList($this->form['skills']),
            'biography' => $this->form['biography'] ?: null,
            'years_experience' => $this->form['years_experience'] !== ''
                ? (int) $this->form['years_experience']
                : null,
            'phones' => array_values(array_filter([$this->form['phone']])),
            'whatsapp' => $this->form['whatsapp'] ?: null,
            'email' => $this->form['email'] ? strtolower(trim($this->form['email'])) : null,
            'address' => array_filter(['city' => $this->form['city'] ?: null]) ?: null,
            'coverage_area' => $this->splitList($this->form['coverage']),
            'languages' => $this->splitList($this->form['languages']),
            'is_published' => (bool) $this->form['is_published'],
        ];

        DB::transaction(function () use ($attributes, $company) {
            $artisan = $this->editingId ? Artisan::findOrFail($this->editingId) : null;

            if ($this->photo) {
                $attributes['photo_path'] = $this->photo->store('artisans/'.$company->id, 'public');
            }

            if ($artisan) {
                $artisan->update($attributes);

                return;
            }

            $artisan = Artisan::create($attributes + [
                'slug' => $this->uniqueSlug($attributes['full_name']),
                'artisan_number' => 'ART-'.strtoupper(Str::random(6)),
                'user_id' => null,
            ]);

            // Same identity infrastructure as businesses and documents: one
            // immutable token, resolved by the public verifier.
            $token = VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => Artisan::class,
                'subject_id' => $artisan->id,
            ]);

            $artisan->forceFill([
                'verification_token_id' => $token->id,
                'is_verified' => true,
            ])->save();
        });

        $this->reset('editing', 'editingId', 'form', 'photo');
    }

    /** @return array<int, string>|null */
    protected function splitList(?string $value): ?array
    {
        $items = collect(explode(',', (string) $value))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->values()
            ->all();

        return $items ?: null;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'artisan';
        $slug = $base;

        while (Artisan::withTrashed()->acrossAllCompanies()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    public function render(): View
    {
        return view('livewire.business.artisans', [
            'artisans' => Artisan::query()->orderBy('full_name')->get(),
        ])->layout('components.layouts.app', ['title' => 'Artisans', 'active' => 'business']);
    }
}
