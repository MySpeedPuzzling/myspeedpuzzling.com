<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\RoundTimezone;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;

/**
 * One result a row of a player's spreadsheet says, ready for ImportedResultWriter
 * (docs/features/data-import/writer.md). The puzzle is a catalogue puzzle and the day is exact - unlisted puzzles
 * and month-only dates never get here.
 */
readonly final class ImportedResult
{
    /** The rows of one day keep their order as seconds after noon - an hour of them, so noon never becomes night */
    public const int POSITIONS_PER_DAY = 3600;

    /**
     * @param string $solvedDay Y-m-d, the day the puzzle was solved - as the file says it
     * @param string $timezone IANA zone the day is read in (zoneOf() the player)
     * @param null|int $seconds null = solved without a time
     * @param null|bool $firstTry what the file says; null = it says nothing, which is "no" (README D15)
     * @param list<string> $groupPlayers the other people, as the add form passes them: names, or `#code` of a
     *     registered player. Empty = solo
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
    ) {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $solvedDay, new DateTimeZone('UTC'));

        if ($day === false || $day->format('Y-m-d') !== $solvedDay) {
            throw new InvalidArgumentException(sprintf('"%s" is not a day (Y-m-d).', $solvedDay));
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
     * `finished_at`: the day, as the add form stores a picked date - midnight, no zone.
     */
    public function finishedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->solvedDay . ' 00:00:00', new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * `tracked_at` (README D5): noon of the solve day in the player's zone, plus the row's place among the rows as
     * seconds - an order that stays inside the hour after noon. Stored like every `tracked_at`, in the server's zone (UTC).
     */
    public function trackedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->solvedDay . ' 12:00:00', new DateTimeZone($this->timezone))
            ->modify(sprintf('+%d seconds', $this->position % self::POSITIONS_PER_DAY))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    public function isFirstTry(): bool
    {
        // A result without a time is never a first try - AddPuzzleTrackingHandler stores none either
        return $this->firstTry === true && $this->seconds !== null;
    }
}
