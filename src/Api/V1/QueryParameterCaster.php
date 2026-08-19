<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * castFn callbacks for API Platform QueryParameter declarations. A cast runs
 * before validation and must never throw - whatever cannot be cast is returned
 * untouched so that the constraints report it as a 422, not a 500.
 */
final class QueryParameterCaster
{
    /**
     * Trims surrounding whitespace so that the length constraint judges what
     * the search will actually use.
     */
    /**
     * Surrounding whitespace never counts; an empty (or whitespace-only) value is
     * the same as not sending the parameter at all - a cleared search box must not
     * turn into a 422.
     */
    public static function trim(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * "a,b" => ['a', 'b']: a comma-separated list parameter (OpenAPI style
     * form / explode false). Empty items are dropped, so "a,,b" and "a, b"
     * both mean ['a', 'b'].
     *
     * @return mixed list<string> for a string input
     */
    public static function commaSeparatedList(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $items = [];

        foreach (explode(',', $value) as $item) {
            $item = trim($item);

            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
