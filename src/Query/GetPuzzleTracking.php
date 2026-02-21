<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleTrackingNotFound;
use SpeedPuzzling\Web\Results\TrackedPuzzleDetail;

readonly final class GetPuzzleTracking
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PuzzleTrackingNotFound
     */
    public function byId(string $trackingId): TrackedPuzzleDetail
    {
        if (Uuid::isValid($trackingId) === false) {
            throw new PuzzleTrackingNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle_tracking.id as tracking_id,
    puzzle.id AS puzzle_id,
    puzzle_tracking.team ->> 'team_id' AS team_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_tracking.player_id AS player_id,
    pieces_count,
    player.name AS player_name,
    puzzle_tracking.comment,
    manufacturer.name AS manufacturer_name,
    manufacturer.id AS manufacturer_id,
    finished_at,
    finished_puzzle_photo,
    CASE
        WHEN puzzle_tracking.team IS NOT NULL THEN
            JSON_AGG(
                JSON_BUILD_OBJECT(
                    'player_id', player_elem.player ->> 'player_id',
                    'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                    'player_code', p.code,
                    'player_country', p.country,
                    'is_private', p.is_private
                ) ORDER BY player_elem.ordinality
            )
        ELSE NULL
    END AS players
FROM puzzle_tracking
    LEFT JOIN LATERAL json_array_elements(puzzle_tracking.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON puzzle_tracking.team IS NOT NULL
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
    INNER JOIN puzzle ON puzzle.id = puzzle_tracking.puzzle_id
    INNER JOIN player ON puzzle_tracking.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle_tracking.id = :trackingId
GROUP BY puzzle_tracking.id, puzzle.id, player.id, manufacturer.id
SQL;

        /**
         * @var false|array{
         *     tracking_id: string,
         *     team_id: null|string,
         *     player_id: string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     manufacturer_name: string,
         *     manufacturer_id: string,
         *     puzzle_image: null|string,
         *     pieces_count: int,
         *     comment: null|string,
         *     players: null|string,
         *     finished_at: null|string,
         *     finished_puzzle_photo: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'trackingId' => $trackingId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PuzzleTrackingNotFound();
        }

        return TrackedPuzzleDetail::fromDatabaseRow($row);
    }
}
