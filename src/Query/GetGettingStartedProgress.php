<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\GettingStartedProgress;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\HintType;

readonly final class GetGettingStartedProgress
{
    /**
     * The Hub card is for newcomers only: players who registered longer ago than this
     * never get it, so nobody who has been around for years is handed a beginner's list.
     */
    private const string NEWCOMER_WINDOW = '-14 days';

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function forPlayer(PlayerProfile $profile): GettingStartedProgress
    {
        $query = <<<SQL
SELECT
    player.registered_at > :newcomerSince AS is_newcomer,
    EXISTS (SELECT 1 FROM puzzle_solving_time WHERE puzzle_solving_time.player_id = player.id) AS has_logged_puzzle,
    EXISTS (SELECT 1 FROM collection_item WHERE collection_item.player_id = player.id) AS has_puzzle_in_library,
    EXISTS (SELECT 1 FROM dismissed_hint WHERE dismissed_hint.player_id = player.id AND dismissed_hint.type = :hintType) AS dismissed,
    EXISTS (SELECT 1 FROM dismissed_hint WHERE dismissed_hint.player_id = player.id AND dismissed_hint.type = :statisticsSeen) AS has_seen_statistics,
    EXISTS (SELECT 1 FROM dismissed_hint WHERE dismissed_hint.player_id = player.id AND dismissed_hint.type = :leaderboardSeen) AS has_seen_leaderboard
FROM player
WHERE player.id = :playerId
SQL;

        /** @var false|array{is_newcomer: bool, has_logged_puzzle: bool, has_puzzle_in_library: bool, dismissed: bool, has_seen_statistics: bool, has_seen_leaderboard: bool} $row */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $profile->playerId,
                'hintType' => HintType::GettingStartedChecklist->value,
                'statisticsSeen' => HintType::GuideStatisticsSeen->value,
                'leaderboardSeen' => HintType::GuideLeaderboardSeen->value,
                'newcomerSince' => $this->clock->now()->modify(self::NEWCOMER_WINDOW)->format('Y-m-d H:i:s'),
            ])
            ->fetchAssociative();

        return new GettingStartedProgress(
            hasLoggedPuzzle: $row !== false && $row['has_logged_puzzle'],
            // A photo is welcome but not required: an upload is where many people stop
            hasFinishedProfile: $profile->playerName !== null && $profile->country !== null,
            hasFavoritePlayer: $profile->favoritePlayers !== [],
            dismissed: $row !== false && $row['dismissed'],
            isNewcomer: $row !== false && $row['is_newcomer'],
            hasPuzzleInLibrary: $row !== false && $row['has_puzzle_in_library'],
            hasSeenStatistics: $row !== false && $row['has_seen_statistics'],
            hasSeenLeaderboard: $row !== false && $row['has_seen_leaderboard'],
        );
    }
}
