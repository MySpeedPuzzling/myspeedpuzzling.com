<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CustomValueProblem;

/**
 * The signed-in player's own fields as inputs (templates/my_fields/_inputs.html.twig) - plain inputs named
 * `my_fields[<field id>]`, on purpose no Symfony form children: nothing typed into one can make the form around
 * it invalid (docs/features/custom-fields.md "The add form").
 */
readonly final class CustomFieldsForm
{
    /** The name of the inputs: `my_fields[<field id>]` */
    public const string INPUT = 'my_fields';

    /**
     * @param list<CustomFieldOverview> $fields
     * @param array<string, string> $inputs what each input holds, keyed by field id - as typed, or the stored value
     * @param array<string, CustomValueProblem> $problems keyed by field id
     */
    public function __construct(
        public array $fields,
        public array $inputs = [],
        public array $problems = [],
    ) {
    }

    public function input(CustomFieldOverview $field): string
    {
        return $this->inputs[$field->fieldId] ?? '';
    }

    public function problem(CustomFieldOverview $field): null|CustomValueProblem
    {
        return $this->problems[$field->fieldId] ?? null;
    }

    public function hasAnyInput(): bool
    {
        foreach ($this->fields as $field) {
            if (trim($this->input($field)) !== '') {
                return true;
            }
        }

        return false;
    }
}
