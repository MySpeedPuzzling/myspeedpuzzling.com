<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class CompetitionRefereeListItem
{
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public null|CountryCode $playerCountry,
        public DateTimeImmutable $addedAt,
        public null|string $addedByName,
        // Activity (GetCompetitionReferees::withActivity(), the referees page; the wizard's rows keep the defaults):
        // results whose last change was theirs, and the last change - in which round, shown in that round's zone
        public int $resultsEntered = 0,
        public null|DateTimeImmutable $lastEnteredAt = null,
        public null|string $lastRoundName = null,
        public null|string $lastRoundTimezone = null,
    ) {
    }

    /**
     * @param array{player_id: string, player_code: string, player_name: null|string, player_country: null|string, added_at: string, added_by_name: null|string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            addedAt: new DateTimeImmutable($row['added_at']),
            addedByName: $row['added_by_name'],
        );
    }

    /**
     * @param array{player_id: string, player_code: string, player_name: null|string, player_country: null|string, added_at: string, added_by_name: null|string, results_entered: int|string, last_entered_at: null|string, last_round_name: null|string, last_round_timezone: null|string, last_round_country_code: null|string, last_round_series_country_code: null|string} $row
     */
    public static function fromDatabaseRowWithActivity(array $row): self
    {
        $lastEnteredAt = $row['last_entered_at'] !== null ? new DateTimeImmutable($row['last_entered_at']) : null;

        return new self(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            addedAt: new DateTimeImmutable($row['added_at']),
            addedByName: $row['added_by_name'],
            resultsEntered: (int) $row['results_entered'],
            lastEnteredAt: $lastEnteredAt,
            lastRoundName: $lastEnteredAt !== null ? $row['last_round_name'] : null,
            lastRoundTimezone: $lastEnteredAt !== null
                ? RoundTimezone::resolve($row['last_round_timezone'], $row['last_round_country_code'], $row['last_round_series_country_code'])
                : null,
        );
    }
}
