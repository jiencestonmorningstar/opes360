<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Item;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Illuminate\Routing\Controller;

/**
 * Public business profiles (Module 4) — the page a business QR opens.
 * Public like the verification pages, and resolved the same way: as the profile
 * company, never unscoped across tenants.
 */
class ProfileController extends Controller
{
    public function business(Company $company)
    {
        return app(CurrentCompany::class)->as($company, function () use ($company) {
            $token = VerificationToken::query()
                ->where('subject_type', Company::class)
                ->where('subject_id', $company->id)
                ->first();

            return view('profile.business', [
                'company' => $company,
                'businessToken' => $token,
                'items' => Item::query()->active()->orderBy('name')->limit(8)->get(),
            ]);
        });
    }

    public function vcard(Company $company)
    {
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
