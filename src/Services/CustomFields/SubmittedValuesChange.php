<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValue;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
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
     * @param array<string, CustomField> $usableFields the acting player's fields that are filled in here, keyed by
     *                                                 field id - anything else is "no such field"
     * @param array<array-key, mixed> $submitted keyed by field id
     *
     * @throws InvalidCustomValues
     */
    public static function apply(CustomValues $stored, array $usableFields, array $submitted): CustomValues
    {
        $changes = [];
        $problems = [];

        foreach ($submitted as $fieldId => $value) {
            $field = $usableFields[strtolower((string) $fieldId)] ?? null;

            // Somebody else's field, an archived one, one that is not for this place: all the same "no such field"
            if ($field === null) {
                $problems[(string) $fieldId] = CustomValueProblem::UnknownField;

                continue;
            }

            $current = $stored->of($field->id->toString());

            // The form sends every field back. What comes back as it is stored is no change and is not judged again:
            // a choice the field had when the value was stored, a number the rules of today would ask about
            if ($current !== null && is_string($value) && trim($value) === CustomValues::toInput($current)) {
                continue;
            }

            try {
                $changes[$field->id->toString()] = CustomValues::normalise($field->type, $field->choices, $value);
            } catch (InvalidCustomValue $refused) {
                $problems[(string) $fieldId] = $refused->problem;
            }
        }

        if ($problems !== []) {
            throw new InvalidCustomValues($problems);
        }

        return $stored->with($changes);
    }
}
