<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The field checks PuzzleDifficultyEstimateInput and PuzzleDifficultyEstimateModelInput share: each one reads a field,
 * collects what is wrong with it in $errors (so one answer lists every mistake) and answers null then.
 */
trait DifficultyEstimateFieldChecks
{
    /**
     * @param array<mixed> $fields
     * @param list<string> $known
     *
     * @return array<string, string>
     */
    private static function unknownFields(array $fields, array $known): array
    {
        $errors = [];

        foreach (array_keys($fields) as $field) {
            if (in_array($field, $known, true) === false) {
                $errors[(string) $field] = sprintf('is not a field of this endpoint (known: %s).', implode(', ', $known));
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $fields
     * @param array<string, string> $errors
     */
    private static function requiredString(array $fields, string $field, int $maxLength, array &$errors): null|string
    {
        $value = $fields[$field] ?? null;
        $value = is_string($value) ? trim($value) : null;

        if ($value === null || $value === '') {
            $errors[$field] = 'is required - a non-empty string.';

            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $errors[$field] = sprintf('can be at most %d characters long.', $maxLength);

            return null;
        }

        // Postgres text refuses NUL - found only at the flush, it would fail the whole batch with a 500
        if (str_contains($value, "\0")) {
            $errors[$field] = 'must not contain NUL characters.';

            return null;
        }

        return $value;
    }

    /**
     * A JSON number (integer or not) within the bounds - NaN and infinities cannot come from JSON.
     *
     * @param array<mixed> $fields
     * @param array<string, string> $errors
     */
    private static function requiredNumber(array $fields, string $field, float $minimum, float $maximum, array &$errors): null|float
    {
        $value = $fields[$field] ?? null;

        if ((is_int($value) || is_float($value)) === false || $value < $minimum || $value > $maximum) {
            $errors[$field] = sprintf('is required - a number from %s to %s.', $minimum, $maximum);

            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<mixed> $fields
     * @param array<string, string> $errors
     */
    private static function requiredInt(array $fields, string $field, int $minimum, int $maximum, array &$errors): null|int
    {
        $value = $fields[$field] ?? null;

        if (is_int($value) === false || $value < $minimum || $value > $maximum) {
            $errors[$field] = sprintf('is required - a whole number from %d to %d.', $minimum, $maximum);

            return null;
        }

        return $value;
    }

    /**
     * ISO 8601, UTC when it has no offset; stored in UTC. Null when absent or null (the caller uses the moment of the
     * push then).
     *
     * @param array<string, string> $errors
     */
    private static function optionalMoment(mixed $value, string $field, array &$errors): null|DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            $errors[$field] = 'must be an ISO 8601 date-time, e.g. "2026-10-19T03:00:00Z", or null.';

            return null;
        }

        try {
            $moment = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            $errors[$field] = 'is not a real date-time.';

            return null;
        }

        // A day that does not exist (2026-02-30) rolls over silently - parsed in its own offset, the day must stay
        if ($moment->format('Y-m-d') !== substr($value, 0, 10)) {
            $errors[$field] = 'is not a real date-time.';

            return null;
        }

        return $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
