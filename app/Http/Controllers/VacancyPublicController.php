<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AbortsForSuspendedCompany;
use App\Models\Scopes\CompanyScope;
use App\Models\Vacancy;
use App\Services\Recruitment\RecruitmentPipeline;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * The public face of a vacancy — what the share link opens.
 *
 * Same tenancy rule as the public form: the visitor is not a user, the token
 * names the company, and nothing here is ever resolved across tenants. The
 * company id on every record written comes from the vacancy the token found,
 * never from anything in the request.
 */
class VacancyPublicController extends Controller
{
    use AbortsForSuspendedCompany;

    public function show(string $token)
    {
        $vacancy = $this->findVacancy($token);

        if ($vacancy === null) {
            abort(404);
        }

        return view('public.vacancy', [
            'vacancy' => $vacancy,
            'company' => $vacancy->company,
        ]);
    }

    public function submit(Request $request, string $token)
    {
        $vacancy = $this->findVacancy($token);

        if ($vacancy === null) {
            abort(404);
        }

        if (! $vacancy->isOpen()) {
            return redirect()->to('/jobs/'.$token);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:120'],
            'cover_note' => ['nullable', 'string', 'max:5000'],
            // The pipeline re-checks extension against sniffed mime; this rule
            // is the polite refusal, that one is the real gate.
            'cv' => ['nullable', 'file', 'max:10240',
                'mimes:'.implode(',', array_keys(RecruitmentPipeline::CV_ALLOWED))],
        ], [
            'first_name.required' => 'Tell us your first name.',
            'last_name.required' => 'Tell us your last name.',
        ]);

        try {
            app(RecruitmentPipeline::class)->apply($vacancy, $validated, $request->file('cv'));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['cv' => $e->getMessage()]);
        }

        return redirect()->to('/jobs/'.$token.'/thanks');
    }

    public function thanks(string $token)
    {
        $vacancy = $this->findVacancy($token);

        if ($vacancy === null) {
            abort(404);
        }

        return view('public.vacancy-thanks', [
            'vacancy' => $vacancy,
            'company' => $vacancy->company,
        ]);
    }

    protected function findVacancy(string $token): ?Vacancy
    {
        $vacancy = Vacancy::query()
            ->withoutGlobalScope(CompanyScope::class)
            // The position must also escape the tenant scope: the visitor has
            // no current company, and CompanyScope fails closed — an eager
            // load left scoped would render every public advert titleless.
            ->with(['company', 'position' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)])
            ->where('share_token', $token)
            ->first();

        $this->abortIfSuspended($vacancy?->company);

        return $vacancy;
    }
}
