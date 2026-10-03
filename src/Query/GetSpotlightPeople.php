<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\SpotlightPeople;
use SpeedPuzzling\Web\Results\SpotlightPerson;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\CommunityScope;

/**
 * The spotlight's three people lists - Most active this month, Most followed, New faces - for the world or one
 * country, in one statement over the precomputed community_player_stats (docs/features/players-page/README.md).
 *
 * Public profiles only, for everybody: nobody is ranked differently for different viewers. Players the viewer hides
 * are filtered inside each ranked list, so the ranks close up (docs/features/player-blocklist.md).
 */
readonly final class GetSpotlightPeople
{
    // Rows per list: five shown, five more behind "Show more"
    public const int LIMIT = 10;

    // New faces: registered within this many days, with at least one result
    public const int NEW_FACES_DAYS = 14;

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private ClockInterface $clock,
    ) {
    }

    public function forScope(CommunityScope $scope): SpotlightPeople
    {
        $parameters = [
            'newSince' => $this->clock->now()->modify(sprintf('-%d days', self::NEW_FACES_DAYS))->format('Y-m-d H:i:s'),
        ];
        $inScope = '';

        if ($scope->country !== null) {
            $inScope = 'AND p.country = :country';
            $parameters['country'] = $scope->country->name;
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('p.id');
        $limit = self::LIMIT;
        $mostActive = SpotlightPeople::MOST_ACTIVE;
        $mostFollowed = SpotlightPeople::MOST_FOLLOWED;
        $newFaces = SpotlightPeople::NEW_FACES;

        $columns = <<<SQL
    p.id AS player_id,
    p.code AS player_code,
    p.name AS player_name,
    p.country AS player_country,
    p.avatar AS player_avatar,
    p.registered_at,
    s.solved_total,
    s.solves_this_month,
    s.pieces_this_month,
    s.favorites_count
SQL;

        $people = <<<SQL
FROM community_player_stats s
JOIN player p ON p.id = s.player_id
WHERE p.is_private = false
    {$inScope}
    {$notHidden}
SQL;

        $query = <<<SQL
(
    SELECT
        '{$mostActive}' AS list,
        RANK() OVER (ORDER BY s.solves_this_month DESC, s.pieces_this_month DESC) AS list_rank,
        ROW_NUMBER() OVER (ORDER BY s.solves_this_month DESC, s.pieces_this_month DESC, p.id) AS list_position,
{$columns}
    {$people}
        AND s.solves_this_month > 0
    ORDER BY list_position
    LIMIT {$limit}
)
UNION ALL
(
    SELECT
        '{$mostFollowed}',
        RANK() OVER (ORDER BY s.favorites_count DESC),
        ROW_NUMBER() OVER (ORDER BY s.favorites_count DESC, s.solved_total DESC, p.id) AS list_position,
{$columns}
    {$people}
        AND s.favorites_count > 0
    ORDER BY list_position
    LIMIT {$limit}
)
UNION ALL
(
    SELECT
        '{$newFaces}',
        ROW_NUMBER() OVER (ORDER BY p.registered_at DESC, p.id),
        ROW_NUMBER() OVER (ORDER BY p.registered_at DESC, p.id) AS list_position,
{$columns}
    {$people}
        AND p.registered_at >= :newSince
        AND s.solved_total > 0
    ORDER BY list_position
    LIMIT {$limit}
)
ORDER BY list, list_position
SQL;

        $mostActivePeople = [];
        $mostFollowedPeople = [];
        $newFacesPeople = [];

        foreach ($this->database->executeQuery($query, $parameters)->fetchAllAssociative() as $row) {
            /**
             * @var array{
             *     list: string,
             *     list_rank: int|string,
             *     list_position: int|string,
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             *     registered_at: string,
             *     solved_total: int|string,
             *     solves_this_month: int|string,
             *     pieces_this_month: int|string,
             *     favorites_count: int|string,
             * } $row
             */
            $list = $row['list'];
            unset($row['list'], $row['list_position']);
            $person = SpotlightPerson::fromDatabaseRow($row);

            if ($list === SpotlightPeople::MOST_ACTIVE) {
                $mostActivePeople[] = $person;
            } elseif ($list === SpotlightPeople::MOST_FOLLOWED) {
                $mostFollowedPeople[] = $person;
            } else {
                $newFacesPeople[] = $person;
            }
        }

        return new SpotlightPeople(
            mostActive: $mostActivePeople,
            mostFollowed: $mostFollowedPeople,
            newFaces: $newFacesPeople,
        );
    }
}
