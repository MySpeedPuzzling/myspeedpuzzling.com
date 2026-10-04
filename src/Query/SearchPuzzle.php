<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Results\AutocompletePuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use SpeedPuzzling\Web\Value\PuzzleSearchList;
use SpeedPuzzling\Web\Value\PuzzleSearchListKind;

readonly final class SearchPuzzle
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<int> $difficultyTiers
     *
     * @throws ManufacturerNotFound
     */
    public function countByUserInput(
        null|string $brandId,
        null|string $search,
        PiecesRange $pieces,
        null|string $tag,
        array $difficultyTiers = [],
        null|PuzzleSearchList $list = null,
        null|string $listPlayerId = null,
    ): int {
        if ($brandId !== null && Uuid::isValid($brandId) === false) {
            throw new ManufacturerNotFound();
        }

        [$difficultyJoin, $difficultyCondition, $ratedTiers] = self::difficultyFilter($difficultyTiers);

        [$listCondition, $listParams] = self::listFilter($list, $listPlayerId);

        $textSearch = PuzzleTextSearch::fromUserInput($search);
        $textCondition = self::andCondition($textSearch->condition('puzzle'));

        $query = <<<SQL
SELECT
    COUNT(DISTINCT puzzle.id) AS count
FROM puzzle
LEFT JOIN tag_puzzle ON tag_puzzle.puzzle_id = puzzle.id
{$difficultyJoin}
WHERE
    (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
    AND (:brandId::uuid IS NULL OR manufacturer_id = :brandId)
    AND (:minPieces::int IS NULL OR pieces_count >= :minPieces)
    AND (:maxPieces::int IS NULL OR pieces_count <= :maxPieces)
    {$textCondition}
    AND (:useTags = false OR tag_puzzle.tag_id IN(:tag))
    {$difficultyCondition}
    {$listCondition}
SQL;

        $params = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ...$textSearch->parameters(),
            'brandId' => $brandId,
            'minPieces' => $pieces->minPieces,
            'maxPieces' => $pieces->maxPieces,
            'useTags' => $tag !== null ? 1 : 0,
            'tag' => $tag ? [$tag] : [],
        ];

        $types = [
            'tag' => ArrayParameterType::STRING,
        ];

        if ($ratedTiers !== []) {
            $params['difficultyTiers'] = $ratedTiers;
            $types['difficultyTiers'] = ArrayParameterType::INTEGER;
        }

        $params = [...$params, ...$listParams];

        $count = $this->database
            ->executeQuery($query, $params, $types)
            ->fetchOne();
        assert(is_int($count));

        return $count;
    }

    /**
     * @param list<int> $difficultyTiers
     *
     * @return list<PuzzleOverview>
     *
     * @throws ManufacturerNotFound
     */
    public function byUserInput(
        null|string $brandId,
        null|string $search,
        PiecesRange $pieces,
        null|string $tag,
        null|string $sortBy = null,
        int $offset = 0,
        int $limit = 20,
        array $difficultyTiers = [],
        null|PuzzleSearchList $list = null,
        null|string $listPlayerId = null,
    ): array {
        if ($brandId !== null && Uuid::isValid($brandId) === false) {
            throw new ManufacturerNotFound();
        }

        if (in_array($sortBy, PuzzleSearchCriteria::VALID_SORTS, true) === false) {
            $sortBy = 'most-solved';
        }

        [$difficultyJoin, $difficultyCondition, $ratedTiers] = self::difficultyFilter($difficultyTiers);

        [$listCondition, $listParams] = self::listFilter($list, $listPlayerId);

        $textSearch = PuzzleTextSearch::fromUserInput($search);
        $textCondition = self::andCondition($textSearch->condition('puzzle'));
        $matchScore = $textSearch->score('puzzle');

        // Every other sort keeps the match as its tiebreak
        $orderBy = match ($sortBy) {
            'best-match' => 'pb.match_score DESC, solved_times DESC, pb.puzzle_name, m.name',
            'least-solved' => 'solved_times ASC, pb.match_score DESC, pb.puzzle_name, m.name',
            'a-z' => 'pb.puzzle_name, pb.match_score DESC, m.name, pb.pieces_count',
            'z-a' => 'pb.puzzle_name DESC, pb.match_score DESC, m.name DESC, pb.pieces_count',
            'easiest' => 'pdi.difficulty_score ASC NULLS LAST, pb.match_score DESC, pb.puzzle_name',
            'hardest' => 'pdi.difficulty_score DESC NULLS LAST, pb.match_score DESC, pb.puzzle_name',
            default => 'solved_times DESC, pb.match_score DESC, pb.puzzle_name, m.name',
        };

        $query = <<<SQL
WITH puzzle_base AS (
    SELECT DISTINCT ON (puzzle.id)
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
        puzzle.alternative_names AS puzzle_alternative_names,
        puzzle.pieces_count,
        puzzle.is_available,
        puzzle.approved AS puzzle_approved,
        puzzle.manufacturer_id,
        puzzle.ean AS puzzle_ean,
        puzzle.identification_number AS puzzle_identification_number,
        puzzle.hide_image_until,
        {$matchScore} AS match_score
    FROM puzzle
    LEFT JOIN tag_puzzle ON tag_puzzle.puzzle_id = puzzle.id
    {$difficultyJoin}
    WHERE
        (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
        AND (:brandId::uuid IS NULL OR manufacturer_id = :brandId)
        AND (:minPieces::int IS NULL OR pieces_count >= :minPieces)
        AND (:maxPieces::int IS NULL OR pieces_count <= :maxPieces)
        {$textCondition}
        AND (:useTags = 0 OR tag_puzzle.tag_id IN(:tag))
        {$difficultyCondition}
        {$listCondition}
)
SELECT
    pb.puzzle_id,
    pb.puzzle_name,
    pb.puzzle_image,
    pb.puzzle_image_ratio,
    pb.puzzle_alternative_names,
    pb.pieces_count,
    pb.is_available,
    pb.puzzle_approved,
    m.name AS manufacturer_name,
    m.id AS manufacturer_id,
    m.slug AS manufacturer_slug,
    pb.puzzle_ean,
    pb.puzzle_identification_number,
    pb.hide_image_until,
    COALESCE(ps.solved_times_count, 0) AS solved_times,
    ps.average_time_solo,
    ps.fastest_time_solo,
    ps.average_time_duo,
    ps.fastest_time_duo,
    ps.average_time_team,
    ps.fastest_time_team
FROM puzzle_base pb
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = pb.puzzle_id
LEFT JOIN puzzle_difficulty pdi ON pdi.puzzle_id = pb.puzzle_id
INNER JOIN manufacturer m ON pb.manufacturer_id = m.id
ORDER BY {$orderBy}
LIMIT :limit OFFSET :offset
SQL;

        $params = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ...$textSearch->parameters(),
            'brandId' => $brandId,
            'limit' => $limit,
            'minPieces' => $pieces->minPieces,
            'maxPieces' => $pieces->maxPieces,
            'offset' => $offset,
            'useTags' => $tag !== null ? 1 : 0,
            'tag' => $tag ? [$tag] : [],
        ];

        $types = [
            'tag' => ArrayParameterType::STRING,
        ];

        if ($ratedTiers !== []) {
            $params['difficultyTiers'] = $ratedTiers;
            $types['difficultyTiers'] = ArrayParameterType::INTEGER;
        }

        $params = [...$params, ...$listParams];

        $data = $this->database
            ->executeQuery($query, $params, $types)
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleOverview {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     puzzle_alternative_names: string,
             *     puzzle_approved: bool,
             *     manufacturer_name: string,
             *     manufacturer_id: string,
             *     manufacturer_slug: null|string,
             *     pieces_count: int,
             *     average_time_solo: null|string,
             *     fastest_time_solo: null|int,
             *     average_time_duo: null|string,
             *     fastest_time_duo: null|int,
             *     average_time_team: null|string,
             *     fastest_time_team: null|int,
             *     solved_times: int,
             *     is_available: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string,
             *     hide_image_until: null|string,
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }

    private static function andCondition(string $condition): string
    {
        return $condition === '' ? '' : 'AND ' . $condition;
    }

    /**
     * Tiers plus PuzzleSearchCriteria::UNRATED_DIFFICULTY for puzzles without a tier yet
     * (no puzzle_difficulty row, or one with too little data for a tier).
     *
     * @param list<int> $difficultyTiers
     *
     * @return array{string, string, list<int>} join, condition, the real tiers to bind
     */
    private static function difficultyFilter(array $difficultyTiers): array
    {
        if ($difficultyTiers === []) {
            return ['', '', []];
        }

        $ratedTiers = array_values(array_filter(
            $difficultyTiers,
            static fn (int $tier): bool => $tier !== PuzzleSearchCriteria::UNRATED_DIFFICULTY,
        ));

        $conditions = [];

        if ($ratedTiers !== []) {
            $conditions[] = 'pd.difficulty_tier IN(:difficultyTiers)';
        }

        if (count($ratedTiers) !== count($difficultyTiers)) {
            $conditions[] = 'pd.difficulty_tier IS NULL';
        }

        return [
            'LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = puzzle.id',
            'AND (' . implode(' OR ', $conditions) . ')',
            $ratedTiers,
        ];
    }

    /**
     * "Only puzzles from my ..." as a semi-join on puzzle.id: it never adds rows, so
     * DISTINCT ON / COUNT(DISTINCT) stay as they are and the page and the count
     * cannot disagree. Every kind is scoped to the viewer, which is what makes a
     * collection id of somebody else match nothing.
     *
     * "Solved" is a time of the player's own or one as a team member - the team
     * test is a jsonb containment so custom_pst_team_puzzlers_gin answers it, and
     * it names the player by the constant parameter (see GetUnsolvedPuzzles).
     * "Unsolved" = in any of my collections or borrowed by me, and not solved -
     * the definition of GetUserPuzzleStatuses.
     *
     * @return array{string, array<string, string>}
     */
    private static function listFilter(null|PuzzleSearchList $list, null|string $listPlayerId): array
    {
        if ($list === null) {
            return ['', []];
        }

        if ($listPlayerId === null) {
            throw new \LogicException('Filtering by a puzzle list needs the player whose list it is.');
        }

        $params = ['listPlayerId' => $listPlayerId];

        $borrowed = 'SELECT lp.puzzle_id FROM lent_puzzle lp WHERE lp.current_holder_player_id = :listPlayerId AND (lp.owner_player_id IS NULL OR lp.owner_player_id <> :listPlayerId)';
        $teamSolved = "pst.team IS NOT NULL AND (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:listPlayerId AS UUID)))";

        $subquery = match ($list->kind) {
            PuzzleSearchListKind::Library => 'SELECT ci.puzzle_id FROM collection_item ci WHERE ci.player_id = :listPlayerId AND ci.collection_id IS NULL',
            PuzzleSearchListKind::Collection => 'SELECT ci.puzzle_id FROM collection_item ci WHERE ci.player_id = :listPlayerId AND ci.collection_id = :listCollectionId',
            PuzzleSearchListKind::Wishlist => 'SELECT wli.puzzle_id FROM wish_list_item wli WHERE wli.player_id = :listPlayerId',
            PuzzleSearchListKind::SellSwap => 'SELECT ssli.puzzle_id FROM sell_swap_list_item ssli WHERE ssli.player_id = :listPlayerId',
            PuzzleSearchListKind::Borrowed => $borrowed,
            PuzzleSearchListKind::Lent => 'SELECT lp.puzzle_id FROM lent_puzzle lp WHERE lp.owner_player_id = :listPlayerId',
            PuzzleSearchListKind::Solved => "SELECT pst.puzzle_id FROM puzzle_solving_time pst WHERE pst.player_id = :listPlayerId
                UNION ALL
                SELECT pst.puzzle_id FROM puzzle_solving_time pst WHERE {$teamSolved}",
            PuzzleSearchListKind::Unsolved => "SELECT owned.puzzle_id FROM (
                    SELECT ci.puzzle_id FROM collection_item ci WHERE ci.player_id = :listPlayerId
                    UNION
                    {$borrowed}
                ) owned
                WHERE NOT EXISTS (
                    SELECT 1 FROM puzzle_solving_time pst
                    WHERE pst.puzzle_id = owned.puzzle_id
                      AND (pst.player_id = :listPlayerId OR ({$teamSolved}))
                )",
        };

        if ($list->kind === PuzzleSearchListKind::Collection) {
            assert($list->collectionId !== null);
            $params['listCollectionId'] = $list->collectionId;
        }

        return ["AND puzzle.id IN ({$subquery})", $params];
    }

    /**
     * Every puzzle that carries the given barcode as one of its EANs, leading
     * zeros tolerated (barcode scanners and typed-in codes differ in exactly
     * that) - never a longer code containing it (PuzzleTextSearch).
     * Secret competition puzzles (hide_until in the future) are never returned;
     * an embargoed image (hide_image_until) comes back null.
     *
     * @return list<PuzzleOverview>
     */
    public function allByEan(string $ean): array
    {
        $textSearch = PuzzleTextSearch::fromUserInput($ean);
        $barcodeCondition = $textSearch->barcodeCondition('puzzle');

        if ($barcodeCondition === null) {
            return [];
        }

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.alternative_names AS puzzle_alternative_names,
    puzzle.pieces_count,
    puzzle.is_available,
    puzzle.approved AS puzzle_approved,
    puzzle.ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle.hide_image_until,
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    COALESCE(ps.solved_times_count, 0) AS solved_times,
    ps.average_time_solo,
    ps.fastest_time_solo,
    ps.average_time_duo,
    ps.fastest_time_duo,
    ps.average_time_team,
    ps.fastest_time_team
FROM puzzle
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = puzzle.id
WHERE
    {$barcodeCondition}
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
ORDER BY solved_times DESC, puzzle.name, manufacturer.name
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                ...$textSearch->parameters(),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleOverview {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     puzzle_alternative_names: string,
             *     puzzle_approved: bool,
             *     manufacturer_name: string,
             *     manufacturer_id: string,
             *     pieces_count: int,
             *     average_time_solo: null|string,
             *     fastest_time_solo: null|int,
             *     average_time_duo: null|string,
             *     fastest_time_duo: null|int,
             *     average_time_team: null|string,
             *     fastest_time_team: null|int,
             *     solved_times: int,
             *     is_available: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string,
             *     hide_image_until: null|string,
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $rows);
    }

    /**
     * The puzzle picker of one brand. Unapproved puzzles are listed, secret ones (hide_until in the future) never -
     * apart from the ones in rounds of $secretPuzzlesOfCompetitionId, for its organiser adding puzzles to a round.
     *
     * @return array<AutocompletePuzzle>
     */
    public function byBrandId(string $brandId, null|string $secretPuzzlesOfCompetitionId = null): array
    {
        $params = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'manufacturerId' => $brandId,
        ];
        $secretPuzzlesOfCompetition = '';

        if ($secretPuzzlesOfCompetitionId !== null) {
            $secretPuzzlesOfCompetition = 'OR puzzle.id IN (
            SELECT crp.puzzle_id
            FROM competition_round_puzzle crp
            INNER JOIN competition_round cr ON cr.id = crp.round_id
            WHERE cr.competition_id = :competitionId
        )';
            $params['competitionId'] = $secretPuzzlesOfCompetitionId;
        }

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.alternative_names AS puzzle_alternative_names,
    puzzle.pieces_count,
    puzzle.approved AS puzzle_approved,
    manufacturer.name AS manufacturer_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE ean END AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number
FROM puzzle
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE
    manufacturer_id = :manufacturerId
    AND (
        puzzle.hide_until IS NULL
        OR puzzle.hide_until <= :now::timestamp
        {$secretPuzzlesOfCompetition}
    )
ORDER BY puzzle.name ASC, manufacturer_name ASC, pieces_count ASC
SQL;

        $data = $this->database
            ->executeQuery($query, $params)
            ->fetchAllAssociative();

        return array_map(static function (array $row): AutocompletePuzzle {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     puzzle_alternative_names: string,
             *     manufacturer_name: string,
             *     pieces_count: int,
             *     puzzle_approved: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string
             * } $row
             */

            return AutocompletePuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
