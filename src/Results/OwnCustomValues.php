<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CustomValues;

/**
 * The signed-in viewer's own fields and their own values on some results - what the owner-only block of the result
 * detail shows (docs/features/custom-fields.md). Built by OwnCustomValuesOnResults for the viewer alone.
 */
readonly final class OwnCustomValues
{
    /**
     * @param list<CustomFieldOverview> $fields the viewer's fields for results, in their order
     * @param array<string, CustomValues> $values keyed by solving time id - results without any are left out
     */
    public function __construct(
        public array $fields,
        public array $values,
    ) {
    }

    /**
     * The fields that hold a value on the result, with it.
     *
     * @return list<array{field: CustomFieldOverview, value: string|int|float|bool|list<string>}>
     */
    public function filledOn(string $solvingTimeId): array
    {
        $values = $this->values[$solvingTimeId] ?? null;
        $filled = [];

        if ($values === null) {
            return [];
        }

        foreach ($this->fields as $field) {
            $value = $values->of($field->fieldId);

            if ($value !== null) {
                $filled[] = ['field' => $field, 'value' => $value];
            }
        }

        return $filled;
    }
}
