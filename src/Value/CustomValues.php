<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValue;

/**
 * A player's own values on one row (a result, a collection item), keyed by the id of the custom field -
 * what the jsonb column holds (docs/features/custom-fields.md). One stored value per type:
 *
 * - text: a string of at most 500 characters, valid UTF-8 without control characters (line breaks and tabs stay) and
 *   without the two characters XML can not hold (U+FFFE, U+FFFF)
 * - number: an int, or a float when it has a fraction
 * - date: `Y-m-d`
 * - yes/no: true / false (no answer = no value)
 * - one of a list: one of the field's choices
 * - tags: a list of free tags (the field has no fixed list) - at most 20, each at most 50 characters, each once
 *   whatever its letter case
 * - rating: a whole number from 1 to 5
 *
 * normalise() is the one parser of a submitted value - the forms, the handlers, later the import and My data.
 */
readonly final class CustomValues
{
    public const int TEXT_MAX_LENGTH = 500;
    public const int MAX_TAGS = 20;
    public const int TAG_MAX_LENGTH = 50;
    public const int RATING_MAX = 5;
    private const float NUMBER_LIMIT = 1_000_000_000_000.0;

    /**
     * @param array<string, string|int|float|bool|list<string>> $values
     */
    public function __construct(
        public array $values = [],
    ) {
    }

    /**
     * What the column holds - anything that is not a value of one of the types is left out, never an error.
     */
    public static function fromStored(mixed $stored): self
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (is_array($stored) === false) {
            return new self();
        }

        $values = [];

        foreach ($stored as $fieldId => $value) {
            if (is_string($fieldId) === false) {
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $values[$fieldId] = $value;
            } elseif (is_array($value)) {
                $values[$fieldId] = array_values(array_filter($value, is_string(...)));
            }
        }

        return new self($values);
    }

    /**
     * @param array<string, null|string|int|float|bool|list<string>> $changes null takes the field's value away
     */
    public function with(array $changes): self
    {
        $values = $this->values;

        foreach ($changes as $fieldId => $value) {
            if ($value === null) {
                unset($values[$fieldId]);
            } else {
                $values[$fieldId] = $value;
            }
        }

        return new self($values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return null|string|int|float|bool|list<string>
     */
    public function of(string $fieldId): null|string|int|float|bool|array
    {
        return $this->values[$fieldId] ?? null;
    }

    /**
     * One submitted value as it is stored, null = no value (empty input, no tags).
     *
     * @param list<string> $choices the field's choices (one of a list); for any other type they mean nothing
     * @return null|string|int|float|bool|list<string>
     *
     * @throws InvalidCustomValue
     */
    public static function normalise(CustomFieldType $type, array $choices, mixed $submitted): null|string|int|float|bool|array
    {
        if ($submitted === null) {
            return null;
        }

        if (is_string($submitted)) {
            $submitted = trim($submitted);

            if ($submitted === '') {
                return null;
            }
        }

        // Before any type reads it: a NUL byte or broken UTF-8 is a wrong value of every type - PHP's own date and
        // text functions answer them with errors, not with "no"
        if (is_string($submitted) && self::isStorableText($submitted, multiline: true) === false) {
            throw new InvalidCustomValue(match ($type) {
                CustomFieldType::Number => CustomValueProblem::NotANumber,
                CustomFieldType::Date => CustomValueProblem::NotADate,
                CustomFieldType::YesNo => CustomValueProblem::NotYesNo,
                CustomFieldType::Choice => CustomValueProblem::UnknownChoice,
                CustomFieldType::Rating => CustomValueProblem::NotARating,
                CustomFieldType::Text, CustomFieldType::Tags => CustomValueProblem::NotValid,
            });
        }

        return match ($type) {
            CustomFieldType::Text => self::text($submitted),
            CustomFieldType::Number => self::number($submitted),
            CustomFieldType::Date => self::date($submitted),
            CustomFieldType::YesNo => self::yesNo($submitted),
            CustomFieldType::Choice => self::choice($submitted, $choices),
            CustomFieldType::Tags => self::tags($submitted),
            CustomFieldType::Rating => self::rating($submitted),
        };
    }

    /**
     * A stored value as the text of its form input (yes/no: `yes` / `no`, tags: joined by a comma).
     *
     * @param null|string|int|float|bool|list<string> $value
     */
    public static function toInput(null|string|int|float|bool|array $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', $value),
            is_float($value) => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'),
            default => (string) $value,
        };
    }

    /**
     * @throws InvalidCustomValue
     */
    private static function text(mixed $submitted): string
    {
        if (is_string($submitted) === false) {
            throw new InvalidCustomValue(CustomValueProblem::NotValid);
        }

        $text = str_replace(["\r\n", "\r"], "\n", $submitted);

        if (self::isStorableText($text, multiline: true) === false) {
            throw new InvalidCustomValue(CustomValueProblem::NotValid);
        }

        if (mb_strlen($text) > self::TEXT_MAX_LENGTH) {
            throw new InvalidCustomValue(CustomValueProblem::TooLong);
        }

        return $text;
    }

    /**
     * Text the database can hold, a page can show and an XML export can carry: valid UTF-8 without control characters
     * (a NUL byte or broken UTF-8 is refused by jsonb - as a failed write, not as a wrong value) and without U+FFFE
     * and U+FFFF, which no XML 1.0 document may hold (lone surrogates are no valid UTF-8 already). Line breaks and
     * tabs only where the text has lines. Checked before anything else is done with the text: a regular expression
     * answers broken UTF-8 with nothing at all.
     */
    public static function isStorableText(string $text, bool $multiline): bool
    {
        if (mb_check_encoding($text, 'UTF-8') === false) {
            return false;
        }

        return preg_match($multiline ? '/[^\P{Cc}\n\r\t]|[\x{FFFE}\x{FFFF}]/u' : '/\p{Cc}|[\x{FFFE}\x{FFFF}]/u', $text) === 0;
    }

    /**
     * `12`, `12.5`, `12,5`, `1 250` - a decimal comma or point, spaces between thousands. One comma or point with
     * exactly three digits behind a group of one to three (`1,250`, `12.500`) can be a thousand or a fraction - it is
     * refused instead of guessed (`0,125` and `1234,567` can only be fractions).
     *
     * @throws InvalidCustomValue
     */
    private static function number(mixed $submitted): int|float
    {
        if (is_int($submitted) || is_float($submitted)) {
            $number = (float) $submitted;
        } elseif (is_string($submitted)) {
            $compact = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $submitted);

            if (preg_match('/^-?\d{1,15}(?:[.,]\d{1,6})?$/', $compact) !== 1) {
                throw new InvalidCustomValue(CustomValueProblem::NotANumber);
            }

            if (preg_match('/^-?[1-9]\d{0,2}[.,]\d{3}$/', $compact) === 1) {
                throw new InvalidCustomValue(CustomValueProblem::AmbiguousNumber);
            }

            $number = (float) str_replace(',', '.', $compact);
        } else {
            throw new InvalidCustomValue(CustomValueProblem::NotANumber);
        }

        if (is_finite($number) === false || abs($number) > self::NUMBER_LIMIT) {
            throw new InvalidCustomValue(CustomValueProblem::NotANumber);
        }

        return floor($number) === $number ? (int) $number : $number;
    }

    /**
     * @throws InvalidCustomValue
     */
    private static function date(mixed $submitted): string
    {
        if (is_string($submitted) === false) {
            throw new InvalidCustomValue(CustomValueProblem::NotADate);
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $submitted);

        if ($date === false || $date->format('Y-m-d') !== $submitted) {
            throw new InvalidCustomValue(CustomValueProblem::NotADate);
        }

        $year = (int) $date->format('Y');

        if ($year < 1900 || $year > 2100) {
            throw new InvalidCustomValue(CustomValueProblem::NotADate);
        }

        return $submitted;
    }

    /**
     * @throws InvalidCustomValue
     */
    private static function yesNo(mixed $submitted): bool
    {
        if (is_bool($submitted)) {
            return $submitted;
        }

        return match (is_string($submitted) ? strtolower($submitted) : null) {
            'yes', '1', 'true' => true,
            'no', '0', 'false' => false,
            default => throw new InvalidCustomValue(CustomValueProblem::NotYesNo),
        };
    }

    /**
     * @param list<string> $choices
     *
     * @throws InvalidCustomValue
     */
    private static function choice(mixed $submitted, array $choices): string
    {
        if (is_string($submitted) === false || in_array($submitted, $choices, true) === false) {
            throw new InvalidCustomValue(CustomValueProblem::UnknownChoice);
        }

        return $submitted;
    }

    /**
     * `1` to `5`, nothing else - not `4.5`, not `04`, not `5 stars`.
     *
     * @throws InvalidCustomValue
     */
    private static function rating(mixed $submitted): int
    {
        if (is_string($submitted) && preg_match('/^[1-9]$/', $submitted) === 1) {
            $submitted = (int) $submitted;
        }

        if (is_int($submitted) === false || $submitted < 1 || $submitted > self::RATING_MAX) {
            throw new InvalidCustomValue(CustomValueProblem::NotARating);
        }

        return $submitted;
    }

    /**
     * One text with commas between the tags, or a list of tags.
     *
     * @return null|list<string>
     *
     * @throws InvalidCustomValue
     */
    private static function tags(mixed $submitted): null|array
    {
        if (is_string($submitted)) {
            $submitted = explode(',', $submitted);
        }

        if (is_array($submitted) === false) {
            throw new InvalidCustomValue(CustomValueProblem::NotValid);
        }

        $tags = [];

        foreach ($submitted as $tag) {
            if (is_string($tag) === false || mb_check_encoding($tag, 'UTF-8') === false) {
                throw new InvalidCustomValue(CustomValueProblem::NotValid);
            }

            $tag = trim((string) preg_replace('/\s+/u', ' ', $tag));

            if (self::isStorableText($tag, multiline: false) === false) {
                throw new InvalidCustomValue(CustomValueProblem::NotValid);
            }

            if ($tag === '') {
                continue;
            }

            if (mb_strlen($tag) > self::TAG_MAX_LENGTH) {
                throw new InvalidCustomValue(CustomValueProblem::TagTooLong);
            }

            $tags[mb_strtolower($tag)] ??= $tag;
        }

        if (count($tags) > self::MAX_TAGS) {
            throw new InvalidCustomValue(CustomValueProblem::TooManyTags);
        }

        return $tags === [] ? null : array_values($tags);
    }
}
