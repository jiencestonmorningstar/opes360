<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AbortsForSuspendedCompany;
use App\Models\Artisan;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Item;
use App\Models\Scopes\CompanyScope;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public business profiles (Module 4) — the page a business QR opens.
 * Public like the verification pages, and resolved the same way: as the profile
 * company, never unscoped across tenants.
 */
class ProfileController extends Controller
{
    use AbortsForSuspendedCompany;

    public function business(Company $company)
    {
        $this->abortIfSuspended($company);

        // response()->view() renders immediately, so every query the template
        // runs still happens inside the company scope. Returning a View instance
        // would defer rendering to after the scope is restored, and the tenant
        // scope fails closed — the page would silently lose its data.
        return app(CurrentCompany::class)->as($company, fn () => response()->view('profile.business', [
            'company' => $company,
            'businessToken' => VerificationToken::query()
                ->where('subject_type', Company::class)
                ->where('subject_id', $company->id)
                ->first(),
            'items' => Item::query()->active()->orderBy('name')->limit(8)->get(),
            'reviews' => CompanyReview::query()->published()->latest()->get(),
        ]));
    }

    /**
     * A visitor's review. Stored unpublished — nothing appears publicly until
     * the business approves it on the moderation screen.
     */
    public function submitReview(Request $request, Company $company)
    {
        $this->abortIfSuspended($company);

        // Honeypot: humans never see the field, bots fill it. Answer exactly as
        // if the review were accepted so the bot learns nothing.
        if ($request->filled('website_url')) {
            return redirect()->route('profile.business', $company)
                ->with('review_status', 'Thanks — your review is awaiting approval.');
        }

        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:80'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        app(CurrentCompany::class)->as($company, fn () => CompanyReview::create($data + [
            'is_published' => false,
            // Hashed, never raw: enough to group repeat abuse for triage.
            'submitted_ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
        ]));

        return redirect()->route('profile.business', $company)
            ->with('review_status', 'Thanks — your review is awaiting approval.');
    }

    /**
     * Artisan profiles are resolved without the tenant scope (there is no session
     * on a public scan), then everything else runs as the artisan's own company.
     */
    public function artisan(string $slug)
    {
        $artisan = Artisan::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless($artisan->is_published, 404);

        $company = Company::findOrFail($artisan->company_id);

        $this->abortIfSuspended($company);

        // Rendered inside the scope for the same reason as the business profile.
        return app(CurrentCompany::class)->as($company, fn () => response()->view('profile.artisan', [
            'artisan' => $artisan->load(['testimonials' => fn ($q) => $q->where('is_published', true)]),
            'company' => $company,
        ]));
    }

    public function artisanVcard(string $slug)
    {
        $artisan = Artisan::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless($artisan->is_published, 404);

        $company = Company::find($artisan->company_id);

        $this->abortIfSuspended($company);

        $lines = array_filter([
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:'.$artisan->full_name,
            'N:'.$artisan->full_name.';;;;',
            $artisan->occupation ? 'TITLE:'.$artisan->occupation : null,
            $company ? 'ORG:'.$company->name : null,
            $artisan->email ? 'EMAIL:'.$artisan->email : null,
            ...collect($artisan->phones ?? [])->map(fn ($p) => 'TEL;TYPE=CELL:'.$p)->all(),
            'URL:'.route('profile.artisan', $artisan),
            'NOTE:Verified with OPES360',
            'END:VCARD',
        ]);

        return response(implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$artisan->slug.'.vcf"',
        ]);
    }

    public function vcard(Company $company)
    {
        $this->abortIfSuspended($company);

        $lines = array_filter([
            'BEGIN:VCARD',
            'VERSION:3.0',
            'KIND:org',
            'FN:'.$company->name,
            'ORG:'.$company->name,
            $company->email ? 'EMAIL:'.$company->email : null,
            ...collect($company->phones ?? [])->map(fn ($p) => 'TEL;TYPE=WORK:'.$p)->all(),
            $company->website ? 'URL:'.$company->website : null,
            $company->address_line1
                ? 'ADR;TYPE=WORK:;;'.$company->address_line1.';'.($company->city ?? '').';'.($company->region ?? '').';;'.($company->country ?? '')
                : null,
            'NOTE:'.($company->motto ?? 'Powered by OPES360'),
            'END:VCARD',
        ]);

        return response(implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$company->slug.'.vcf"',
        ]);
    }
}
