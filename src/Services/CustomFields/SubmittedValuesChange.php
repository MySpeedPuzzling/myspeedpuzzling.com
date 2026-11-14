<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValue;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\CustomValueProblem;
use SpeedPuzzling\Web\Value\CustomValues;

/**
 * What a row holds once the submitted values are taken - the one rule of both writers (a player's row of a result, a
 * collection item; docs/features/custom-fields.md). Everything is checked before anything changes: one refused value
 * refuses them all.
 *
 * Only the fields given change: a value as it was typed (CustomValues::normalise() reads it), null or an empty text
 * takes the field's value away, a field left out keeps what it has.
 */
final class SubmittedValuesChange
{
    /**
     * For a writer.
     *
     * @param array<string, CustomField> $usableFields the acting player's fields that are filled in here, keyed by
     *                                                 field id - anything else is "no such field"
     * @param array<array-key, mixed> $submitted keyed by field id
     *
     * @throws InvalidCustomValues
     */
    public static function apply(CustomValues $stored, array $usableFields, array $submitted): CustomValues
    {
        $types = [];

        foreach ($usableFields as $fieldId => $field) {
            $types[$fieldId] = [$field->type, $field->choices];
        }

        [$changes, $problems] = self::judge($stored, $types, $submitted);

        if ($problems !== []) {
            throw new InvalidCustomValues($problems);
        }

        return $stored->with($changes);
    }

    /**
     * For a form that must know before anything is written: the same rule, nothing stored, nothing thrown.
     *
     * @param list<CustomFieldOverview> $usableFields the signed-in player's fields the form shows
     * @param array<array-key, mixed> $submitted keyed by field id
     * @return array<string, CustomValueProblem> keyed by the field id as it was sent; empty = every value can be stored
     */
    public static function problems(CustomValues $stored, array $usableFields, array $submitted): array
    {
        $types = [];

        foreach ($usableFields as $field) {
            $types[$field->fieldId] = [$field->type, $field->choices];
        }

        return self::judge($stored, $types, $submitted)[1];
    }

    /**
     * @param array<string, array{CustomFieldType, list<string>}> $types type and choices by field id
     * @param array<array-key, mixed> $submitted
     * @return array{array<string, null|string|int|float|bool|list<string>>, array<string, CustomValueProblem>} the
     *         changes by field id, the problems by the field id as it was sent
     */
    private static function judge(CustomValues $stored, array $types, array $submitted): array
    {
        $changes = [];
        $problems = [];

        foreach ($submitted as $sentFieldId => $value) {
            $fieldId = strtolower((string) $sentFieldId);
            $field = $types[$fieldId] ?? null;

            // Somebody else's field, an archived one, one that is not for this place: all the same "no such field"
            if ($field === null) {
                $problems[(string) $sentFieldId] = CustomValueProblem::UnknownField;

                continue;
            }

            $current = $stored->of($fieldId);

            // The form sends every field back. What comes back as it is stored is no change and is not judged again:
            // a choice the field had when the value was stored, a number the rules of today would ask about
            if ($current !== null && is_string($value) && trim($value) === CustomValues::toInput($current)) {
                continue;
            }

            try {
                $changes[$fieldId] = CustomValues::normalise($field[0], $field[1], $value);
            } catch (InvalidCustomValue $refused) {
                $problems[(string) $sentFieldId] = $refused->problem;
            }
        }

        return [$changes, $problems];
    }
}
