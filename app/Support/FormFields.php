<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The Opes Forms field-type catalogue.
 *
 * The builder offers these types, the public page renders them and the
 * submission validator derives its rules from them. One catalogue, three
 * consumers — the same reasoning as the permission catalogue: a type the
 * builder offers but the validator does not understand would accept anything,
 * silently.
 */
class FormFields
{
    /** type => [label, icon, has options?] */
    public const TYPES = [
        'short_text' => ['label' => 'Short answer', 'icon' => 'document', 'options' => false],
        'long_text' => ['label' => 'Paragraph', 'icon' => 'document', 'options' => false],
        'choice' => ['label' => 'Multiple choice', 'icon' => 'check-circle', 'options' => true],
        'checkboxes' => ['label' => 'Checkboxes', 'icon' => 'check-circle', 'options' => true],
        'dropdown' => ['label' => 'Dropdown', 'icon' => 'chevron-down', 'options' => true],
        'date' => ['label' => 'Date', 'icon' => 'calendar', 'options' => false],
        'number' => ['label' => 'Number', 'icon' => 'chart-bar', 'options' => false],
        'email' => ['label' => 'Email', 'icon' => 'user', 'options' => false],
        'phone' => ['label' => 'Phone', 'icon' => 'user', 'options' => false],
    ];

    public static function isValidType(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public static function hasOptions(string $type): bool
    {
        return self::TYPES[$type]['options'] ?? false;
    }

    /** A fresh field definition for the builder. */
    public static function blank(string $type): array
    {
        return [
            'id' => (string) Str::ulid(),
            'type' => self::isValidType($type) ? $type : 'short_text',
            'label' => '',
            'help' => '',
            'required' => false,
            'options' => self::hasOptions($type) ? ['Option 1'] : [],
        ];
    }

    /**
     * Fill in anything missing from a stored definition, so a field written by
     * an older version of the builder can never fatal the renderer.
     */
    public static function normalise(array $field): array
    {
        return [
            'id' => (string) ($field['id'] ?? Str::ulid()),
            'type' => self::isValidType($field['type'] ?? '') ? $field['type'] : 'short_text',
            'label' => (string) ($field['label'] ?? ''),
            'help' => (string) ($field['help'] ?? ''),
            'required' => (bool) ($field['required'] ?? false),
            'options' => array_values(array_filter(
                array_map(strval(...), (array) ($field['options'] ?? [])),
                fn (string $option) => trim($option) !== '',
            )),
        ];
    }

    /**
     * Laravel validation rules for a submission against these definitions.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array{rules: array<string, mixed>, attributes: array<string, string>}
     */
    public static function submissionRules(array $fields): array
    {
        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $field = self::normalise($field);
            $key = 'answers.'.$field['id'];

            $rule = [$field['required'] ? 'required' : 'nullable'];

            $rule[] = match ($field['type']) {
                'checkboxes' => 'array',
                'number' => 'numeric',
                'date' => 'date',
                'email' => 'email',
                default => 'string',
            };

            // Rule::in(), not a comma-joined "in:" string — an option label
            // containing a comma (e.g. "Yes, please") would otherwise split
            // into two options and never validate again.
            if ($field['type'] === 'checkboxes') {
                $rules[$key.'.*'] = ['string', Rule::in($field['options'])];
            } elseif (in_array($field['type'], ['choice', 'dropdown'], true)) {
                $rule[] = Rule::in($field['options']);
            } elseif (in_array($field['type'], ['short_text', 'phone'], true)) {
                $rule[] = 'max:255';
            } elseif ($field['type'] === 'long_text') {
                $rule[] = 'max:5000';
            }

            $rules[$key] = $rule;
            $attributes[$key] = $field['label'] !== '' ? $field['label'] : 'this field';
        }

        return ['rules' => $rules, 'attributes' => $attributes];
    }
}
