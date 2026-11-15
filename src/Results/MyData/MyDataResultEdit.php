<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\MyData;

use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * What "My data" holds in the editable fields of one result (docs/features/my-data.md "Editing one row") - as typed,
 * or as stored. The row sheet posts all of them; a cell edited in place is the stored row with one field replaced.
 *
 * Nothing else of a result is edited here: the puzzle, the people, the photo, the event and "unboxed" stay as stored.
 */
readonly final class MyDataResultEdit
{
    public const string TIME = 'time';
    public const string DATE = 'date';
    public const string FIRST_TRY = 'first_try';
    public const string COMMENT = 'comment';

    public const array FIELDS = [self::TIME, self::DATE, self::FIRST_TRY, self::COMMENT];

    public function __construct(
        // The three boxes of the time, as typed - ignored for a result without a time
        public string $hours,
        public string $minutes,
        public string $seconds,
        // The day as `d.m.Y` (the edit form's format), '' = none typed: a result that knows only its month or year
        // keeps what it knows, a result without a time loses its date
        public string $day,
        public bool $firstAttempt,
        public string $comment,
    ) {
    }

    public static function ofStored(SolvedPuzzleDetail $time): self
    {
        $seconds = $time->time ?? 0;

        return new self(
            hours: (string) intdiv($seconds, 3600),
            minutes: (string) intdiv($seconds % 3600, 60),
            seconds: (string) ($seconds % 60),
            day: self::storedDay($time),
            firstAttempt: $time->firstAttempt,
            comment: $time->comment ?? '',
        );
    }

    /**
     * The exact day a result holds, as the edit form shows it - '' while only its month or year is known.
     */
    public static function storedDay(SolvedPuzzleDetail $time): string
    {
        return $time->finishedAt !== null && $time->datePrecision === DatePrecision::Day ? $time->finishedAt->format('d.m.Y') : '';
    }

    /**
     * One field as a cell of the table holds it (`data-value`, the `from` of an edit in place): the time as
     * `h:mm:ss` ('' = no time), the day as `Y-m-d` ('' = no exact day), the first try as `1` / `0`, the comment.
     */
    public function cell(string $field): string
    {
        return match ($field) {
            self::TIME => $this->totalSeconds() > 0 ? sprintf('%d:%02d:%02d', (int) $this->hours, (int) $this->minutes, (int) $this->seconds) : '',
            self::DATE => $this->day !== '' ? implode('-', array_reverse(explode('.', $this->day))) : '',
            self::FIRST_TRY => $this->firstAttempt ? '1' : '0',
            default => $this->comment,
        };
    }

    /**
     * This row with one cell's value replaced. Null = the value is no value of that field at all.
     */
    public function withCell(string $field, string $value): null|self
    {
        if ($field === self::TIME) {
            if (preg_match('/^\s*(?:(\d{1,3}):)?(\d{1,2}):(\d{1,2})\s*$/', $value, $parts) !== 1) {
                return null;
            }

            return new self($parts[1] !== '' ? $parts[1] : '0', $parts[2], $parts[3], $this->day, $this->firstAttempt, $this->comment);
        }

        if ($field === self::DATE) {
            $value = trim($value);

            if ($value !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
                return null;
            }

            return new self($this->hours, $this->minutes, $this->seconds, $value === '' ? '' : "{$parts[3]}.{$parts[2]}.{$parts[1]}", $this->firstAttempt, $this->comment);
        }

        if ($field === self::FIRST_TRY) {
            return in_array($value, ['0', '1'], true)
                ? new self($this->hours, $this->minutes, $this->seconds, $this->day, $value === '1', $this->comment)
                : null;
        }

        if ($field === self::COMMENT) {
            return new self($this->hours, $this->minutes, $this->seconds, $this->day, $this->firstAttempt, $value);
        }

        return null;
    }

    /**
     * Whether the fields hold the same as another row - the comment as it would be stored (trimmed).
     */
    public function equals(self $other): bool
    {
        foreach (self::FIELDS as $field) {
            if (trim($this->cell($field)) !== trim($other->cell($field))) {
                return false;
            }
        }

        return true;
    }

    private function totalSeconds(): int
    {
        return (int) $this->hours * 3600 + (int) $this->minutes * 60 + (int) $this->seconds;
    }
}
