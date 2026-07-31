<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Scopes\CompanyScope;
use App\Support\FormFields;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * The public face of Opes Forms — what a share link opens.
 *
 * Same tenancy rule as verification: the visitor is not a user, the token
 * names the company, and nothing here is ever resolved across tenants.
 */
class FormPublicController extends Controller
{
    public function show(string $token)
    {
        $form = $this->findForm($token);

        if ($form === null) {
            abort(404);
        }

        return view('public.form', [
            'form' => $form,
            'company' => $form->company,
            'fields' => $form->fieldDefinitions(),
        ]);
    }

    public function submit(Request $request, string $token)
    {
        $form = $this->findForm($token);

        if ($form === null) {
            abort(404);
        }

        if (! $form->isOpen()) {
            return redirect()->to('/f/'.$token);
        }

        $fields = $form->fieldDefinitions();
        ['rules' => $rules, 'attributes' => $attributes] = FormFields::submissionRules($fields);

        $validated = Validator::make($request->all(), $rules, [], $attributes)->validate();

        // Only catalogued field ids are stored — extra keys in the request are
        // dropped, so a crafted POST cannot smuggle payload into the answers.
        $answers = collect($fields)
            ->mapWithKeys(fn (array $field) => [$field['id'] => $validated['answers'][$field['id']] ?? null])
            ->filter(fn ($answer) => $answer !== null && $answer !== '' && $answer !== [])
            ->all();

        FormResponse::create([
            'company_id' => $form->company_id,
            'form_id' => $form->id,
            'answers' => $answers,
        ]);

        return redirect()->to('/f/'.$token.'/thanks');
    }

    public function thanks(string $token)
    {
        $form = $this->findForm($token);

        if ($form === null) {
            abort(404);
        }

        return view('public.form-thanks', [
            'form' => $form,
            'company' => $form->company,
        ]);
    }

    protected function findForm(string $token): ?Form
    {
        return Form::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('share_token', $token)
            ->first();
    }
}
