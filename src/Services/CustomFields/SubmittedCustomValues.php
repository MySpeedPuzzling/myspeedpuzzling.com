<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use SpeedPuzzling\Web\Results\CustomFieldsForm;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a request holds in its `my_fields[<field id>]` inputs - read so that nothing sent there can throw:
 * anything that is not a text under a text key is left out.
 */
final class SubmittedCustomValues
{
    /**
     * @return array<string, string> keyed by (lower case) field id, as typed
     */
    public static function of(Request $request): array
    {
        $submitted = $request->request->all()[CustomFieldsForm::INPUT] ?? null;

        if (is_array($submitted) === false) {
            return [];
        }

        $values = [];

        foreach ($submitted as $fieldId => $value) {
            if (is_string($fieldId) && is_string($value)) {
                $values[strtolower($fieldId)] = $value;
            }
        }

        return $values;
    }
}
