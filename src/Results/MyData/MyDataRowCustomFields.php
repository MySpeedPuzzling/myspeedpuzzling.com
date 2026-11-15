<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\MyData;

use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Services\CustomFields\SubmittedValuesChange;
use SpeedPuzzling\Web\Value\CustomValueProblem;
use SpeedPuzzling\Web\Value\CustomValues;
use SpeedPuzzling\Web\Value\MyDataColumns;

/**
 * The signed-in player's own fields on ONE row of "My data" (a result or a puzzle of their collection): the fields
 * they fill there and what the row holds in them. Made by CustomFieldsOfMyDataRow only, for the signed-in player only.
 */
readonly final class MyDataRowCustomFields
{
    /**
     * @param list<CustomFieldOverview> $fields
     */
    public function __construct(
        public array $fields = [],
        public CustomValues $stored = new CustomValues(),
    ) {
    }

    /**
     * @return array<string, string> what each input holds as stored, keyed by field id
     */
    public function storedInputs(): array
    {
        $inputs = [];

        foreach ($this->fields as $field) {
            $inputs[$field->fieldId] = CustomValues::toInput($this->stored->of($field->fieldId));
        }

        return $inputs;
    }

    /**
     * What the inputs hold after a submit: only the fields the form shows, an input not sent keeps the stored value.
     *
     * @param array<string, string> $submitted
     * @return array<string, string>
     */
    public function inputsAfter(array $submitted): array
    {
        $stored = $this->storedInputs();

        return array_intersect_key($submitted, $stored) + $stored;
    }

    /**
     * Checked before anything is written - empty = every value can be stored.
     *
     * @param array<string, string> $inputs
     * @return array<string, CustomValueProblem>
     */
    public function problems(array $inputs): array
    {
        return SubmittedValuesChange::problems($this->stored, $this->fields, $inputs);
    }

    /**
     * @param array<string, string> $inputs
     * @return array<string, string> the inputs that differ from what is stored
     */
    public function changed(array $inputs): array
    {
        return array_diff_assoc(array_intersect_key($inputs, $this->storedInputs()), $this->storedInputs());
    }

    /**
     * The field of a table column (`f:<field id>`) - null when the row has no such field.
     */
    public function fieldOfColumn(string $column): null|CustomFieldOverview
    {
        foreach ($this->fields as $field) {
            if (MyDataColumns::fieldColumn($field->fieldId) === $column) {
                return $field;
            }
        }

        return null;
    }
}
