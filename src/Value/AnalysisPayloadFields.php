<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The checks of an analysis item's common fields, shared by the catalogue-picture analyses (PuzzleImageAnalysisInput)
 * and the finished-photo analyses (FinishedPhotoAnalysisInput): unknown fields, a required short string without NUL,
 * `analyzedAt` (ISO 8601 or null, stored in UTC), `result` (a JSON object within a byte limit, no NUL at any depth).
 * Each adds its message to `$errors` by field and answers null when the value is unusable.
 */
final class AnalysisPayloadFields
{
    private const string DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $known
     *
     * @return array<string, string>
     */
    public static function unknownFields(array $fields, array $known): array
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
     * @param array<string, mixed> $fields
     * @param array<string, string> $errors
     */
    public static function requiredString(array $fields, string $field, int $maxLength, array &$errors): null|string
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
     * @param array<string, string> $errors
     */
    public static function analyzedAt(mixed $value, array &$errors): null|DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) === false || preg_match(self::DATE_TIME_PATTERN, $value) !== 1) {
            $errors['analyzedAt'] = 'must be an ISO 8601 date-time, e.g. "2026-10-18T06:30:00Z", or null.';

            return null;
        }

        try {
            $moment = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            $errors['analyzedAt'] = 'is not a real date-time.';

            return null;
        }

        // A day that does not exist (2026-02-30) rolls over silently - parsed in its own offset, the day must stay
        if ($moment->format('Y-m-d') !== substr($value, 0, 10)) {
            $errors['analyzedAt'] = 'is not a real date-time.';

            return null;
        }

        return $moment->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, string> $errors
     *
     * @return null|array<string, mixed>
     */
    public static function result(mixed $value, int $maxBytes, array &$errors): null|array
    {
        // A JSON object decodes to an array with string keys; [] would be stored as a JSON list
        if (is_array($value) === false || $value === [] || array_is_list($value)) {
            $errors['result'] = 'is required - a JSON object with at least one field.';

            return null;
        }

        // Postgres jsonb refuses \u0000 - found only at the flush, it would fail the whole batch with a 500
        if (self::containsNul($value)) {
            $errors['result'] = 'must not contain NUL characters.';

            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($encoded === false) {
            $errors['result'] = 'cannot be stored as JSON.';

            return null;
        }

        if (strlen($encoded) > $maxBytes) {
            $errors['result'] = sprintf('can be at most %d bytes of JSON (it is %d).', $maxBytes, strlen($encoded));

            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A NUL character in any key or string value, at any depth.
     *
     * @param array<mixed> $values
     */
    private static function containsNul(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && str_contains($key, "\0")) {
                return true;
            }

            if (is_string($value) && str_contains($value, "\0")) {
                return true;
            }

            if (is_array($value) && self::containsNul($value)) {
                return true;
            }
        }

        return false;
    }
}
