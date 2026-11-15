<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\RoundTimezone;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;

/**
 * One result a row of a player's spreadsheet says, ready for ImportedResultWriter
 * (docs/features/data-import/writer.md). The puzzle is a catalogue puzzle - unlisted puzzles never get here. The
 * day is exact, or the first day of the month or year the file knows (docs/features/data-import/date-precision.md).
 */
readonly final class ImportedResult
{
    /**
     * The rows of one day keep their order as steps after noon. A step is longer than the 10 seconds inside which
     * two equal results are "the same form sent twice" to the duplicate rules (DuplicateClassifier), and the steps
     * stay inside the hour after noon, so noon never becomes night
     */
    public const int STEP_SECONDS = 11;
    public const int STEPS = 327;

    /** The add form's largest time: 999:59:59 */
    public const int MAX_SECONDS = 3_599_999;

    /**
     * @param string $solvedDay Y-m-d, the day the puzzle was solved - as the file says it; for a result known to its
     *     month or year only, the first day of that period (the anchor)
     * @param DatePrecision $precision how exactly the file knows the solve day
     * @param string $timezone IANA zone the day is read in (zoneOf() the player)
     * @param null|int $seconds null = solved without a time
     * @param null|bool $firstTry what the file says; null = it says nothing, which is "no" (README D15)
     * @param list<string> $groupPlayers the other people, **one person per entry**: a name, or `#code` of a
     *     registered player. Empty = solo. Unlike the add form's guest box, an entry is never split at commas -
     *     the import has already decided who the people of a row are ("Smith, John" is one guest)
     * @param int $position where the row stands among the rows of its sheet (its row number) - only the order counts
     */
    public function __construct(
        public string $importRowId,
        public string $sourceKey,
        public string $playerId,
        public string $puzzleId,
        public null|int $seconds,
        public string $solvedDay,
        public string $timezone,
        public null|bool $firstTry = null,
        public array $groupPlayers = [],
        public null|string $comment = null,
        public int $position = 0,
        public DatePrecision $precision = DatePrecision::Day,
    ) {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $solvedDay, new DateTimeZone('UTC'));

        if ($day === false || $day->format('Y-m-d') !== $solvedDay) {
            throw new InvalidArgumentException(sprintf('"%s" is not a day (Y-m-d).', $solvedDay));
        }

        if ($precision->anchorOf($day)->format('Y-m-d') !== $solvedDay) {
            throw new InvalidArgumentException(sprintf('A result known to its %s is dated on the first day of it, not "%s".', $precision->value, $solvedDay));
        }

        if (RoundTimezone::isValid($timezone) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a time zone.', $timezone));
        }

        if ($seconds !== null && $seconds <= 0) {
            throw new InvalidArgumentException('A result has a time above zero, or none.');
        }

        if ($position < 0) {
            throw new InvalidArgumentException('A position is not negative.');
        }

        if ($sourceKey === '') {
            throw new InvalidArgumentException('A result of an import has a source key.');
        }
    }

    /**
     * The zone an imported day is read in (README D5): the player's own, else their country's main one, else UTC.
     */
    public static function zoneOf(Player $player): string
    {
        if ($player->timezone !== null && TimezoneIdentifier::isListed($player->timezone)) {
            return $player->timezone;
        }

        return CountryCode::fromCode($player->country)?->defaultTimezone() ?? 'UTC';
    }

    /**
     * `finished_at`: the day, as the add form stores a picked date - midnight, no zone. The first day of the period for
     * a result known to its month or year - `finished_at_precision` says that it is no real day.
     */
    public function finishedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->solvedDay . ' 00:00:00', new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * `tracked_at` (README D5): noon of the solve day in the player's zone, plus the row's place among the rows as
     * steps of STEP_SECONDS - an order that stays inside the hour after noon. Never in the future: a day solved
     * today (or a day that is still ahead in the server's clock for a player far east) would otherwise stand on top
     * of every feed for hours - it is the second before now then. Stored like every `tracked_at`, in the server's
     * zone (UTC). ImportedResultWriter moves it further back when the player already has a result of the puzzle at
     * that moment.
     */
    public function trackedAt(DateTimeImmutable $now): DateTimeImmutable
    {
        $serverZone = new DateTimeZone(date_default_timezone_get());
        $trackedAt = new DateTimeImmutable($this->solvedDay . ' 12:00:00', new DateTimeZone($this->timezone))
            ->modify(sprintf('+%d seconds', ($this->position % self::STEPS) * self::STEP_SECONDS))
            ->setTimezone($serverZone);
        $latest = $now->setTimezone($serverZone)->modify('-1 second');

        return $trackedAt > $latest ? $latest : $trackedAt;
    }

    public function isFirstTry(): bool
    {
        // A result without a time is never a first try - AddPuzzleTrackingHandler stores none either
        return $this->firstTry === true && $this->seconds !== null;
    }
}
