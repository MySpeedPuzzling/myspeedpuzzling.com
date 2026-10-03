<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\SuggestionReason;

/**
 * One person "Suggested for you" on the Players page (GetSuggestedPlayers): always a public profile, so the identity
 * is not masked, plus the one reason shown on the card. Only the fields of that reason are set.
 */
readonly final class SuggestedPlayer
{
    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|string $playerAvatar,
        public null|CountryCode $playerCountry,
        public SuggestionReason $reason,
        // CoPuzzler: who they puzzle with - null when that person is not visible to the viewer (private, a block)
        public null|string $coPuzzlerName,
        // SameEvent: the most recent competition both took part in, labelled like the event badge
        public null|string $eventName,
        // SimilarTime: their best 500-piece solo time
        public null|int $best500Seconds,
        // PuzzlesInCommon: distinct puzzles both logged
        public null|int $sharedPuzzles,
        // Solved a puzzle in the last GetSuggestedPlayers::ACTIVE_DAYS
        public bool $active,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_avatar: null|string,
     *     player_country: null|string,
     *     reason: int|string,
     *     via_player_name: null|string,
     *     via_player_code: null|string,
     *     via_visible: null|bool,
     *     competition_name: null|string,
     *     competition_series_name: null|string,
     *     best500_seconds: null|int|string,
     *     shared_puzzles: null|int|string,
     *     active: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $reason = SuggestionReason::fromPriority((int) $row['reason']);
        $coPuzzlerName = null;

        if ($reason === SuggestionReason::CoPuzzler && $row['via_visible'] === true && $row['via_player_code'] !== null) {
            $coPuzzlerName = $row['via_player_name'] ?? '#' . strtoupper($row['via_player_code']);
        }

        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: strtoupper($row['player_code']),
            playerAvatar: $row['player_avatar'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            reason: $reason,
            coPuzzlerName: $coPuzzlerName,
            eventName: self::eventName($row['competition_name'], $row['competition_series_name']),
            best500Seconds: $row['best500_seconds'] === null ? null : (int) $row['best500_seconds'],
            sharedPuzzles: $row['shared_puzzles'] === null ? null : (int) $row['shared_puzzles'],
            active: $row['active'],
        );
    }

    /**
     * A series edition reads "<series> · <edition>", or just the series when the edition is named like it - the way
     * _competition_badge.html.twig labels it, with full names: a reason is a sentence, not a badge.
     */
    private static function eventName(null|string $competitionName, null|string $seriesName): null|string
    {
        if ($competitionName === null) {
            return null;
        }

        if ($seriesName === null) {
            return $competitionName;
        }

        if (mb_strtolower($competitionName) === mb_strtolower($seriesName)) {
            return $seriesName;
        }

        return $seriesName . ' · ' . $competitionName;
    }
}
