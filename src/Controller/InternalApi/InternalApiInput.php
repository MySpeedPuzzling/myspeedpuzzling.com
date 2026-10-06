<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * The fields of an internal API request body, read one by one: a field of the wrong type or format is collected as an
 * error instead of thrown, so one answer lists every mistake (`throwIfInvalid()`, a 400 with field errors). A field
 * the endpoint does not know is an error too - a typo would otherwise change nothing, silently.
 *
 * Every getter answers null for a field that is absent, null or invalid; `has()` tells an absent field (keep the
 * stored value) from a null one (clear it).
 */
final class InternalApiInput
{
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private const string DATE_TIME_PATTERN = '/^(\d{4}-\d{2}-\d{2})[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $body
     * @param list<string> $knownFields
     */
    public function __construct(
        private readonly array $body,
        array $knownFields,
    ) {
        foreach (array_keys($body) as $field) {
            if (in_array($field, $knownFields, true) === false) {
                $this->errors[(string) $field] = sprintf('is not a field of this endpoint (known: %s).', implode(', ', $knownFields));
            }
        }
    }

    /**
     * @param list<string> $knownFields
     */
    public static function fromRequest(Request $request, array $knownFields): self
    {
        return new self(InternalApiJsonBody::parse($request), $knownFields);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->body);
    }

    public function hasError(string $field): bool
    {
        return array_key_exists($field, $this->errors);
    }

    /**
     * A list of ids that must be sent: absent or null is an error (`[]` is a list).
     *
     * @return null|list<string>
     */
    public function requiredIdList(string $field, string $hint): null|array
    {
        $ids = $this->idList($field);

        if ($ids === null && $this->hasError($field) === false) {
            $this->addError($field, 'is required - ' . $hint);
        }

        return $ids;
    }

    /**
     * A string - trimmed; an empty one is null (it clears a stored value like null does).
     */
    public function string(string $field, bool $required = false, null|int $maxLength = null): null|string
    {
        $value = $this->body[$field] ?? null;

        if ($value !== null && is_string($value) === false) {
            return $this->invalid($field, 'must be a string or null.');
        }

        $value = $value !== null ? trim($value) : null;
        $value = $value === '' ? null : $value;

        if ($value === null && $required) {
            return $this->invalid($field, 'is required.');
        }

        if ($value !== null && $maxLength !== null && mb_strlen($value) > $maxLength) {
            return $this->invalid($field, sprintf('can be at most %d characters long.', $maxLength));
        }

        return $value;
    }

    public function bool(string $field): null|bool
    {
        $value = $this->body[$field] ?? null;

        if ($value !== null && is_bool($value) === false) {
            return $this->invalid($field, 'must be true or false.');
        }

        return $value;
    }

    public function int(string $field, bool $required = false, int $minimum = PHP_INT_MIN, int $maximum = PHP_INT_MAX): null|int
    {
        $value = $this->body[$field] ?? null;

        if ($value === null) {
            return $required ? $this->invalid($field, 'is required.') : null;
        }

        if (is_int($value) === false || $value < $minimum || $value > $maximum) {
            return $this->invalid($field, $maximum === PHP_INT_MAX
                ? sprintf('must be a whole number, at least %d.', $minimum)
                : sprintf('must be a whole number from %d to %d.', $minimum, $maximum));
        }

        return $value;
    }

    /**
     * A calendar day, ISO 8601 (`2026-10-06`; a date-time's day counts too) - at midnight, like the web form stores it.
     */
    public function date(string $field): null|DateTimeImmutable
    {
        $value = $this->string($field);

        if ($value === null) {
            return null;
        }

        $day = preg_match(self::DATE_TIME_PATTERN, $value, $matches) === 1 ? $matches[1] : $value;

        if (preg_match(self::DATE_PATTERN, $day) !== 1) {
            return $this->invalid($field, 'must be an ISO 8601 date, e.g. "2026-10-06".');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        if ($date === false || $date->format('Y-m-d') !== $day) {
            return $this->invalid($field, 'is not a real day.');
        }

        return $date;
    }

    /**
     * A moment, ISO 8601, converted to UTC like the web form stores it. Without an offset (`2026-10-06T10:00`) it is
     * a wall-clock time in `$assumedTimeZone`.
     */
    public function dateTime(string $field, DateTimeZone $assumedTimeZone, bool $required = false): null|DateTimeImmutable
    {
        $value = $this->string($field, $required);

        if ($value === null) {
            return null;
        }

        if (preg_match(self::DATE_TIME_PATTERN, $value, $matches) !== 1) {
            return $this->invalid($field, 'must be an ISO 8601 date-time, e.g. "2026-10-06T10:00:00+02:00".');
        }

        // No offset: a wall-clock time in the assumed zone - it must exist there exactly once (not skipped or repeated
        // by a daylight-saving change), like the organiser's form requires
        if (($matches[4] ?? '') === '') {
            $wallClock = str_replace('T', ' ', preg_replace('/\.\d+$/', '', $value) ?? $value);

            try {
                return RoundTimezone::parseLocal(
                    $wallClock,
                    strlen($wallClock) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s',
                    $assumedTimeZone->getName(),
                );
            } catch (InvalidLocalTime) {
                return $this->invalid($field, sprintf(
                    'does not exist exactly once in %s (a daylight-saving change, or not a real date-time) - send it with an offset.',
                    $assumedTimeZone->getName(),
                ));
            }
        }

        try {
            $moment = new DateTimeImmutable($value, $assumedTimeZone);
        } catch (\Exception) {
            return $this->invalid($field, 'is not a real date-time.');
        }

        if ($moment->format('Y-m-d') !== $matches[1]) {
            return $this->invalid($field, 'is not a real date-time.');
        }

        return $moment->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A list of distinct ids, lower case.
     *
     * @return null|list<string>
     */
    public function idList(string $field): null|array
    {
        $values = $this->body[$field] ?? null;

        if ($values === null) {
            return null;
        }

        if (is_array($values) === false || array_is_list($values) === false) {
            return $this->invalid($field, 'must be a list of ids.');
        }

        $ids = [];

        foreach ($values as $value) {
            if (is_string($value) === false || Uuid::isValid($value) === false) {
                $shown = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return $this->invalid($field, sprintf(
                    'must contain only ids - %s is not one.',
                    is_string($shown) ? $shown : 'a value',
                ));
            }

            $ids[] = strtolower($value);
        }

        return array_values(array_unique($ids));
    }

    /**
     * A field read by one of InternalApiJsonBody's parsers - its 400 becomes this field's error.
     *
     * @template T
     *
     * @param callable(): T $parse
     *
     * @return null|T
     */
    public function parsed(string $field, callable $parse): mixed
    {
        try {
            return $parse();
        } catch (BadRequestHttpException $exception) {
            return $this->invalid($field, $exception->getMessage());
        }
    }

    public function addError(string $field, string $error): void
    {
        $this->errors[$field] = $error;
    }

    /**
     * Violations of a form data object (CompetitionFormData, …) - its property names are this API's field names.
     *
     * @param list<string> $ignoredFields rules of the form that do not apply to this request
     */
    public function addViolations(ConstraintViolationListInterface $violations, array $ignoredFields = []): void
    {
        foreach ($violations as $violation) {
            $field = $violation->getPropertyPath() !== '' ? $violation->getPropertyPath() : 'body';

            if (in_array($field, $ignoredFields, true)) {
                continue;
            }

            // The first problem of a field is the one to fix - and the type errors found above stay
            $this->errors[$field] ??= (string) $violation->getMessage();
        }
    }

    /**
     * @throws InternalApiInvalidInput
     */
    public function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            throw new InternalApiInvalidInput($this->errors);
        }
    }

    private function invalid(string $field, string $error): null
    {
        $this->errors[$field] = $error;

        return null;
    }
}
