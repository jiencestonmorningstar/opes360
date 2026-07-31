<?php

namespace App\Http\Controllers;

use App\Models\Form;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV of a form's responses — one column per current field, one row per
 * submission. Answers to fields that were later deleted are omitted: a column
 * with no header would corrupt the file for the spreadsheet it is destined for.
 */
class FormExportController extends Controller
{
    public function csv(Form $form): StreamedResponse
    {
        $fields = $form->fieldDefinitions();

        $filename = str($form->title)->slug()->limit(60, '')->toString() ?: 'form';

        return response()->streamDownload(function () use ($form, $fields) {
            $out = fopen('php://output', 'w');

            fputcsv($out, array_merge(
                ['Submitted'],
                array_map(fn (array $field) => $field['label'] !== '' ? $field['label'] : 'Untitled', $fields),
            ));

            $form->responses()->latest()->chunk(200, function ($responses) use ($out, $fields) {
                foreach ($responses as $response) {
                    fputcsv($out, array_merge(
                        [$response->created_at->format('Y-m-d H:i')],
                        array_map(fn (array $field) => $response->answerFor($field['id']), $fields),
                    ));
                }
            });

            fclose($out);
        }, $filename.'-responses.csv', ['Content-Type' => 'text/csv']);
    }
}
