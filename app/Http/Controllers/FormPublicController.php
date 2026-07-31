<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Scopes\CompanyScope;
use App\Support\FormFields;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;

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

    /**
     * The iframe variant — what `<iframe src=".../f/{token}/embed">` renders
     * on someone else's website.
     *
     * Everything here is stateless on purpose: inside a third-party iframe
     * the browser strips our session cookie, so redirects with flashed errors
     * would arrive empty. Failures re-render in place with the errors and old
     * input passed as plain variables, and success renders the thanks page
     * directly.
     */
    public function embed(string $token)
    {
        $form = $this->findForm($token);

        if ($form === null) {
            abort(404);
        }

        return view('public.form', [
            'form' => $form,
            'company' => $form->company,
            'fields' => $form->fieldDefinitions(),
            'embed' => true,
            'oldInput' => [],
            'errors' => new ViewErrorBag,
        ]);
    }

    public function embedSubmit(Request $request, string $token)
    {
        $form = $this->findForm($token);

        if ($form === null) {
            abort(404);
        }

        $fields = $form->fieldDefinitions();

        if (! $form->isOpen()) {
            // The view renders its own "not accepting responses" state.
            return $this->embed($token);
        }

        ['rules' => $rules, 'attributes' => $attributes] = FormFields::submissionRules($fields);

        $validator = Validator::make($request->all(), $rules, [], $attributes);

        if ($validator->fails()) {
            return response()->view('public.form', [
                'form' => $form,
                'company' => $form->company,
                'fields' => $fields,
                'embed' => true,
                'oldInput' => (array) $request->input('answers', []),
                'errors' => (new ViewErrorBag)->put('default', $validator->errors()),
            ], 422);
        }

        $validated = $validator->validated();

        $answers = collect($fields)
            ->mapWithKeys(fn (array $field) => [$field['id'] => $validated['answers'][$field['id']] ?? null])
            ->filter(fn ($answer) => $answer !== null && $answer !== '' && $answer !== [])
            ->all();

        FormResponse::create([
            'company_id' => $form->company_id,
            'form_id' => $form->id,
            'answers' => $answers,
        ]);

        return view('public.form-thanks', [
            'form' => $form,
            'company' => $form->company,
            'embed' => true,
        ]);
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
