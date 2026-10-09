<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugAmbiguous;
use SpeedPuzzling\Web\Results\AdminCompetition;
use SpeedPuzzling\Web\Results\AdminCompetitionDetail;
use SpeedPuzzling\Web\Results\AdminCompetitionMaintainer;
use SpeedPuzzling\Web\Results\AdminCompetitionRound;
use SpeedPuzzling\Web\Results\AdminPuzzle;
use SpeedPuzzling\Web\Results\AdminRoundPuzzle;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * Competitions as the internal API shows them to an admin: every competition - approved, pending, rejected, a draft,
 * standalone or an edition of a series - with everything the API can edit. Nothing is hidden from an admin, so
 * puzzles of a round still under embargo are listed too. `status` is the approval state (SQL_APPROVED - an approved
 * draft is approved), `draft` the competition's own flag, `publiclyVisible` the whole rule (drafts included).
 * `resultsCount` counts every time linked to the competition - explicit links and series picks MySpeedPuzzling matched
 * to this edition (docs/features/events-page/high-frequency-series.md), the latter also as `seriesPickResultsCount`;
 * a series pick without an edition belongs to no competition (GetAdminSeries counts it).
 */
readonly final class GetAdminCompetitions
{
    private const string COMPETITION_COLUMNS = <<<SQL
c.id,
c.name,
c.slug,
c.shortcut,
c.description,
c.location,
c.location_country_code,
c.date_from,
c.date_to,
c.link,
c.registration_link,
c.results_link,
c.is_online,
c.logo,
c.series_id,
cs.name AS series_name,
cs.slug AS series_slug,
cs.organization_id AS series_organization_id,
cs.is_draft AS series_is_draft,
cs.rejected_at AS series_rejected_at,
c.organization_id,
o.name AS organization_name,
o.slug AS organization_slug,
c.is_draft,
c.eligibility,
c.tag_id,
tag.name AS tag_name,
c.approved_at,
c.approved_by_player_id,
c.rejected_at,
c.rejection_reason,
c.created_at,
c.added_by_player_id,
added_by.name AS added_by_player_name,
(SELECT COUNT(*) FROM competition_round cr WHERE cr.competition_id = c.id) AS rounds_count,
(SELECT COUNT(*) FROM puzzle_solving_time pst WHERE pst.competition_id = c.id) AS results_count,
(SELECT COUNT(*) FROM puzzle_solving_time pst WHERE pst.competition_id = c.id AND pst.competition_round_id IS NULL) AS results_without_round_count,
(SELECT COUNT(*) FROM puzzle_solving_time pst WHERE pst.competition_id = c.id AND pst.competition_series_id IS NOT NULL) AS series_pick_results_count,
(SELECT COUNT(*) FROM competition_participant cp WHERE cp.competition_id = c.id AND cp.deleted_at IS NULL) AS participants_count
SQL;

    private const string COMPETITION_JOINS = <<<SQL
LEFT JOIN competition_series cs ON cs.id = c.series_id
LEFT JOIN organization o ON o.id = c.organization_id
LEFT JOIN tag ON tag.id = c.tag_id
LEFT JOIN player added_by ON added_by.id = c.added_by_player_id
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Newest first (by the event's date, undated ones by when they were added). `$search` matches the name, slug or
     * shortcut of the competition or of its series, accents and letter case ignored.
     *
     * @return list<AdminCompetition>
     */
    public function search(null|string $search, null|string $status, int $limit, int $offset): array
    {
        [$where, $params] = self::filter($search, $status);
        $columns = self::COMPETITION_COLUMNS;
        $joins = self::COMPETITION_JOINS;
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $approved = IsCompetitionPubliclyVisible::SQL_APPROVED;

        $query = <<<SQL
SELECT {$columns}, ({$visible}) AS publicly_visible, ({$approved}) AS approved
FROM competition c
{$joins}
WHERE {$where}
ORDER BY COALESCE(c.date_from, c.created_at) DESC NULLS LAST, c.name, c.id
LIMIT :limit OFFSET :offset
SQL;

        $rows = $this->database->fetchAllAssociative($query, [...$params, 'limit' => $limit, 'offset' => $offset]);

        return array_map(self::competition(...), $rows);
    }

    /**
     * The editions of a series, by date (undated ones last) - the internal API's series detail
     *
     * @return list<AdminCompetition>
     */
    public function ofSeries(string $seriesId): array
    {
        return $this->listWhere('c.series_id = :id', $seriesId);
    }

    /**
     * The one-time events of an organization, by date (undated ones last) - the internal API's organization detail
     *
     * @return list<AdminCompetition>
     */
    public function oneTimeOfOrganization(string $organizationId): array
    {
        return $this->listWhere('c.series_id IS NULL AND c.organization_id = :id', $organizationId);
    }

    public function count(null|string $search, null|string $status): int
    {
        [$where, $params] = self::filter($search, $status);
        $joins = self::COMPETITION_JOINS;

        $count = $this->database->fetchOne(<<<SQL
SELECT COUNT(*)
FROM competition c
{$joins}
WHERE {$where}
SQL, $params);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * By id, or by slug: a standalone competition's slug first, else an edition's - several editions sharing the slug
     * (it is unique only within a series) are a 409 naming their ids.
     *
     * @throws CompetitionNotFound
     * @throws CompetitionSlugAmbiguous
     */
    public function detail(string $idOrSlug): AdminCompetitionDetail
    {
        $competitionId = Uuid::isValid($idOrSlug) ? strtolower($idOrSlug) : $this->idBySlug($idOrSlug);
        $columns = self::COMPETITION_COLUMNS;
        $joins = self::COMPETITION_JOINS;
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $approved = IsCompetitionPubliclyVisible::SQL_APPROVED;

        $row = $this->database->fetchAssociative(<<<SQL
SELECT {$columns}, ({$visible}) AS publicly_visible, ({$approved}) AS approved
FROM competition c
{$joins}
WHERE c.id = :competitionId
SQL, ['competitionId' => $competitionId]);

        if ($row === false) {
            throw new CompetitionNotFound();
        }

        $competition = self::competition($row);

        return new AdminCompetitionDetail(
            competition: $competition,
            rounds: $this->rounds($competitionId, null),
            taggedPuzzles: $competition->tagId !== null ? $this->taggedPuzzles($competition->tagId) : [],
            maintainers: $this->maintainers($competitionId),
        );
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function round(string $roundId): AdminCompetitionRound
    {
        if (Uuid::isValid($roundId) === false) {
            throw new CompetitionRoundNotFound();
        }

        $competitionId = $this->database->fetchOne(
            'SELECT competition_id FROM competition_round WHERE id = :roundId',
            ['roundId' => $roundId],
        );

        if (is_string($competitionId) === false) {
            throw new CompetitionRoundNotFound();
        }

        return $this->rounds($competitionId, strtolower($roundId))[0] ?? throw new CompetitionRoundNotFound();
    }

    /**
     * @return list<AdminCompetition>
     */
    private function listWhere(string $condition, string $id): array
    {
        if (Uuid::isValid($id) === false) {
            return [];
        }

        $columns = self::COMPETITION_COLUMNS;
        $joins = self::COMPETITION_JOINS;
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $approved = IsCompetitionPubliclyVisible::SQL_APPROVED;

        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT {$columns}, ({$visible}) AS publicly_visible, ({$approved}) AS approved
FROM competition c
{$joins}
WHERE {$condition}
ORDER BY c.date_from NULLS LAST, c.name, c.id
SQL, ['id' => $id]);

        return array_map(self::competition(...), $rows);
    }

    /**
     * @throws CompetitionNotFound
     * @throws CompetitionSlugAmbiguous
     */
    private function idBySlug(string $slug): string
    {
        /** @var list<array{id: string, standalone: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, series_id IS NULL AS standalone FROM competition WHERE slug = :slug ORDER BY series_id NULLS FIRST, id',
            ['slug' => $slug],
        );

        if ($rows === []) {
            throw new CompetitionNotFound();
        }

        if ($rows[0]['standalone'] === true || count($rows) === 1) {
            return $rows[0]['id'];
        }

        throw new CompetitionSlugAmbiguous($slug, array_column($rows, 'id'));
    }

    /**
     * @return list<AdminCompetitionRound>
     */
    private function rounds(string $competitionId, null|string $onlyRoundId): array
    {
        /**
         * @var list<array{
         *     id: string,
         *     competition_id: string,
         *     slug: null|string,
         *     name: string,
         *     category: string,
         *     starts_at: string,
         *     minutes_limit: int,
         *     reveal_delay_minutes: int,
         *     team_size: null|int,
         *     badge_background_color: null|string,
         *     badge_text_color: null|string,
         *     results_link: null|string,
         *     timezone: null|string,
         *     location_country_code: null|string,
         *     series_country_code: null|string,
         *     results_count: int,
         * }> $roundRows
         */
        $roundRows = $this->database->fetchAllAssociative(<<<SQL
SELECT
    cr.id,
    cr.competition_id,
    cr.slug,
    cr.name,
    cr.category,
    cr.starts_at,
    cr.minutes_limit,
    cr.reveal_delay_minutes,
    cr.team_size,
    cr.badge_background_color,
    cr.badge_text_color,
    cr.results_link,
    cr.timezone,
    c.location_country_code,
    cs.location_country_code AS series_country_code,
    (SELECT COUNT(*) FROM puzzle_solving_time pst WHERE pst.competition_round_id = cr.id) AS results_count
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE cr.competition_id = :competitionId
    AND (CAST(:roundId AS UUID) IS NULL OR cr.id = CAST(:roundId AS UUID))
ORDER BY cr.starts_at, cr.name, cr.id
SQL, ['competitionId' => $competitionId, 'roundId' => $onlyRoundId]);

        $revealAt = RoundPuzzleReveal::sqlRevealAt('crp', 'cr');

        /**
         * @var list<array{
         *     round_id: string,
         *     round_puzzle_id: string,
         *     hide_until_round_starts: bool,
         *     hide_mode: null|string,
         *     reveal_mode: string,
         *     hides_everywhere: bool,
         *     reveals_at: null|string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     pieces_count: int,
         *     manufacturer_id: null|string,
         *     manufacturer_name: null|string,
         *     puzzle_ean: null|string,
         *     puzzle_identification_number: null|string,
         *     puzzle_approved: bool,
         *     puzzle_hide_until: null|string,
         *     puzzle_hide_image_until: null|string,
         * }> $puzzleRows
         */
        $puzzleRows = $this->database->fetchAllAssociative(<<<SQL
SELECT
    crp.round_id,
    crp.id AS round_puzzle_id,
    crp.hide_until_round_starts,
    crp.hide_mode,
    crp.reveal_mode,
    crp.hides_everywhere,
    {$revealAt} AS reveals_at,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    p.ean AS puzzle_ean,
    p.identification_number AS puzzle_identification_number,
    p.approved AS puzzle_approved,
    p.hide_until AS puzzle_hide_until,
    p.hide_image_until AS puzzle_hide_image_until
FROM competition_round_puzzle crp
INNER JOIN competition_round cr ON cr.id = crp.round_id
INNER JOIN puzzle p ON p.id = crp.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE cr.competition_id = :competitionId
    AND (CAST(:roundId AS UUID) IS NULL OR cr.id = CAST(:roundId AS UUID))
ORDER BY p.name, p.id
SQL, ['competitionId' => $competitionId, 'roundId' => $onlyRoundId]);

        /** @var array<string, list<AdminRoundPuzzle>> $puzzlesByRound */
        $puzzlesByRound = [];

        foreach ($puzzleRows as $puzzleRow) {
            $puzzlesByRound[$puzzleRow['round_id']][] = new AdminRoundPuzzle(
                roundPuzzleId: $puzzleRow['round_puzzle_id'],
                hideUntilRoundStarts: $puzzleRow['hide_until_round_starts'],
                hideMode: $puzzleRow['hide_mode'],
                revealMode: $puzzleRow['reveal_mode'],
                revealsAt: $puzzleRow['hide_until_round_starts'] ? AdminCompetition::isoDateTime($puzzleRow['reveals_at']) : null,
                hidesEverywhere: $puzzleRow['hides_everywhere'],
                puzzle: AdminPuzzle::fromDatabaseRow($puzzleRow),
            );
        }

        return array_map(
            static fn (array $roundRow): AdminCompetitionRound => AdminCompetitionRound::fromDatabaseRow($roundRow, $puzzlesByRound[$roundRow['id']] ?? []),
            $roundRows,
        );
    }

    /**
     * @return list<AdminPuzzle>
     */
    private function taggedPuzzles(string $tagId): array
    {
        /**
         * @var list<array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     pieces_count: int,
         *     manufacturer_id: null|string,
         *     manufacturer_name: null|string,
         *     puzzle_ean: null|string,
         *     puzzle_identification_number: null|string,
         *     puzzle_approved: bool,
         *     puzzle_hide_until: null|string,
         *     puzzle_hide_image_until: null|string,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    p.ean AS puzzle_ean,
    p.identification_number AS puzzle_identification_number,
    p.approved AS puzzle_approved,
    p.hide_until AS puzzle_hide_until,
    p.hide_image_until AS puzzle_hide_image_until
FROM tag_puzzle tp
INNER JOIN puzzle p ON p.id = tp.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE tp.tag_id = :tagId
ORDER BY p.name, p.id
SQL, ['tagId' => $tagId]);

        return array_map(AdminPuzzle::fromDatabaseRow(...), $rows);
    }

    /**
     * @return list<AdminCompetitionMaintainer>
     */
    private function maintainers(string $competitionId): array
    {
        /** @var list<array{id: string, name: null|string, code: string}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT p.id, p.name, p.code
FROM competition_maintainer cm
INNER JOIN player p ON p.id = cm.player_id
WHERE cm.competition_id = :competitionId
ORDER BY p.name, p.code
SQL, ['competitionId' => $competitionId]);

        return array_map(
            static fn (array $row): AdminCompetitionMaintainer => new AdminCompetitionMaintainer($row['id'], $row['name'], $row['code']),
            $rows,
        );
    }

    /**
     * @return array{string, array<string, string>}
     */
    private static function filter(null|string $search, null|string $status): array
    {
        $conditions = ['TRUE'];
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $conditions[] = <<<SQL
(
    immutable_unaccent(c.name) ILIKE immutable_unaccent(:pattern)
    OR c.slug ILIKE :pattern
    OR c.shortcut ILIKE :pattern
    OR immutable_unaccent(cs.name) ILIKE immutable_unaccent(:pattern)
    OR cs.slug ILIKE :pattern
)
SQL;
            $params['pattern'] = '%' . addcslashes(trim($search), '%_\\') . '%';
        }

        // The approval state, drafts aside: an approved draft is approved (and listed under `draft` too)
        $approved = IsCompetitionPubliclyVisible::SQL_APPROVED;
        $notDraft = IsCompetitionPubliclyVisible::SQL_NOT_DRAFT;
        $conditions[] = match ($status) {
            'approved' => $approved,
            // An edition is rejected with its series
            'pending' => "(c.rejected_at IS NULL AND cs.rejected_at IS NULL AND NOT {$approved})",
            'rejected' => '(c.rejected_at IS NOT NULL OR cs.rejected_at IS NOT NULL)',
            'draft' => "(NOT {$notDraft})",
            default => 'TRUE',
        };

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function competition(array $row): AdminCompetition
    {
        /**
         * @var array{
         *     id: string,
         *     name: string,
         *     slug: null|string,
         *     shortcut: null|string,
         *     description: null|string,
         *     location: null|string,
         *     location_country_code: null|string,
         *     date_from: null|string,
         *     date_to: null|string,
         *     link: null|string,
         *     registration_link: null|string,
         *     results_link: null|string,
         *     is_online: bool,
         *     logo: null|string,
         *     series_id: null|string,
         *     series_name: null|string,
         *     series_slug: null|string,
         *     series_organization_id: null|string,
         *     series_is_draft: null|bool,
         *     series_rejected_at: null|string,
         *     organization_id: null|string,
         *     organization_name: null|string,
         *     organization_slug: null|string,
         *     is_draft: bool,
         *     eligibility: null|string,
         *     tag_id: null|string,
         *     tag_name: null|string,
         *     approved_at: null|string,
         *     approved_by_player_id: null|string,
         *     rejected_at: null|string,
         *     rejection_reason: null|string,
         *     publicly_visible: bool,
         *     approved: bool,
         *     created_at: null|string,
         *     added_by_player_id: null|string,
         *     added_by_player_name: null|string,
         *     rounds_count: int,
         *     results_count: int,
         *     results_without_round_count: int,
         *     series_pick_results_count: int,
         *     participants_count: int,
         * } $row
         */
        return AdminCompetition::fromDatabaseRow($row);
    }
}
