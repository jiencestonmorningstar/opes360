<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\FormResource;
use App\Http\Resources\FormResponseResource;
use App\Models\Form;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Forms and what people submitted to them, read only.
 *
 * Building a form over HTTP is not offered. A form is a set of ordered field
 * definitions — ids, types, options, required flags — that the Livewire
 * builder edits as a whole and the public page renders; posting that JSON
 * blind is strictly harder than dragging four fields on a screen, and nothing
 * about it repeats or arrives from elsewhere. What integrations want from this
 * module is the *data coming back*, which is what these endpoints are.
 *
 * Reading responses is its own permission (`forms.responses`), separate from
 * seeing that a form exists, because submissions are other people's answers —
 * often names, phone numbers and complaints — and the business decides who
 * reads them. The policy already draws that line; this just asks it.
 */
class FormController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Form::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'open', 'closed'])],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $forms = Form::query()
            ->withCount('responses')
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';

                $q->where('title', 'like', $term);
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return FormResource::collection($forms);
    }

    public function show(Form $form): FormResource
    {
        $this->authorize('view', $form);

        return FormResource::make($form->loadCount('responses'));
    }

    /**
     * The submissions.
     *
     * Scoped to one form rather than offered as a flat list, for the same
     * reason payslips are scoped to a run: "every answer anyone has ever given
     * this business" is a question with no honest use.
     */
    public function responses(Request $request, Form $form): AnonymousResourceCollection
    {
        $this->authorize('responses', $form);

        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $responses = $form->responses()
            ->when(isset($filters['from']), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return FormResponseResource::collection($responses);
    }
}
