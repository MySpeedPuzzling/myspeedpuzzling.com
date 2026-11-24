<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\RoundPuzzleLabel;

/**
 * What the sandbox's demo events command (docs/features/sandbox/README.md "Demo events") reads to stay idempotent: its
 * own events by their fixed slugs, their rounds' puzzles, standings, displays and participants, and catalogue puzzles to
 * put into the rounds. Sandbox only - never a page, no viewer.
 */
readonly final class GetSandboxDemoData
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The demo event of this slug - also a draft (the command made it, it decides itself what to do with it).
     *
     * @return null|array{id: string, approved: bool, is_draft: bool}
     */
    public function competitionBySlug(string $slug): null|array
    {
        $row = $this->database->fetchAssociative(<<<SQL
SELECT c.id, c.approved_at IS NOT NULL AS approved, c.is_draft
FROM competition c
WHERE c.slug = :slug
SQL, ['slug' => $slug]);

        if ($row === false) {
            return null;
        }

        /** @var array{id: string, approved: bool, is_draft: bool} $row */
        return $row;
    }

    /**
     * @return array<string, array{id: string, unboxed: bool, puzzle_format: string, table_labels: null|list<string>}> by round name
     */
    public function rounds(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT cr.id, cr.name, cr.unboxed, cr.puzzle_format, cr.table_labels
FROM competition_round cr
WHERE cr.competition_id = :competitionId
ORDER BY cr.starts_at, cr.id
SQL, ['competitionId' => $competitionId]);

        $rounds = [];

        foreach ($rows as $row) {
            /** @var array{id: string, name: string, unboxed: bool, puzzle_format: string, table_labels: null|string} $row */
            $labels = $row['table_labels'] !== null ? json_decode($row['table_labels'], true) : null;
            $rounds[$row['name']] ??= [
                'id' => $row['id'],
                'unboxed' => $row['unboxed'],
                'puzzle_format' => $row['puzzle_format'],
                'table_labels' => is_array($labels) ? array_values(array_map(static fn (mixed $label): string => is_scalar($label) ? (string) $label : '', $labels)) : null,
            ];
        }

        return $rounds;
    }

    /**
     * The round's puzzles in the round's order (RoundPuzzleLabel::sqlOrder()).
     *
     * @return list<array{id: string, puzzle_id: string, pieces: int, unboxed: bool, reveal_mode: string, secret: bool}> id = competition_round_puzzle.id
     */
    public function roundPuzzles(string $roundId): array
    {
        $order = RoundPuzzleLabel::sqlOrder('crp');
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT crp.id, crp.puzzle_id, p.pieces_count, crp.unboxed, crp.reveal_mode, crp.hide_until_round_starts
FROM competition_round_puzzle crp
JOIN puzzle p ON p.id = crp.puzzle_id
WHERE crp.round_id = :roundId
ORDER BY {$order}
SQL, ['roundId' => $roundId]);

        return array_map(static function (array $row): array {
            /** @var array{id: string, puzzle_id: string, pieces_count: int|string, unboxed: bool, reveal_mode: string, hide_until_round_starts: bool} $row */
            return [
                'id' => $row['id'],
                'puzzle_id' => $row['puzzle_id'],
                'pieces' => (int) $row['pieces_count'],
                'unboxed' => $row['unboxed'],
                'reveal_mode' => $row['reveal_mode'],
                'secret' => $row['hide_until_round_starts'],
            ];
        }, $rows);
    }

    /**
     * Approved catalogue puzzles of this piece count anybody may see (no secret, no hidden picture) - a stable sample
     * per event (`$salt`), pictures first. `$woodenFirst` prefers a wooden one (its brand's name says so), `$brand`
     * (a brand's exact name, ignoring case) takes only that brand's.
     *
     * @param list<string> $exceptIds
     * @return list<string> puzzle ids
     */
    public function cataloguePuzzles(null|int $pieces, int $limit, string $salt, array $exceptIds, bool $woodenFirst = false, null|string $brand = null): array
    {
        $brandCondition = $brand !== null ? 'AND lower(m.name) = lower(:brand)' : '';
        $wooden = $woodenFirst ? "(COALESCE(m.name, '') ILIKE '%wood%') DESC," : '';
        $piecesCondition = $pieces !== null ? 'AND p.pieces_count = :pieces' : '';
        $params = [
            'exceptIds' => '{' . implode(',', $exceptIds) . '}',
            'salt' => $salt,
            'limit' => $limit,
        ];

        if ($pieces !== null) {
            $params['pieces'] = $pieces;
        }

        if ($brand !== null) {
            $params['brand'] = $brand;
        }

        $ids = $this->database->fetchFirstColumn(<<<SQL
SELECT p.id
FROM puzzle p
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE p.approved = true
  AND p.hide_until IS NULL
  AND p.hide_image_until IS NULL
  {$piecesCondition}
  {$brandCondition}
  AND NOT (p.id = ANY(CAST(:exceptIds AS uuid[])))
ORDER BY {$wooden} (p.image IS NULL), md5(p.id::text || :salt)
LIMIT :limit
SQL, $params);

        return array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $ids);
    }

    /**
     * @return array<string, array{id: string, slug: string, published: bool}> by standing name
     */
    public function standings(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT cs.id, cs.name, cs.slug, cs.published_at IS NOT NULL AS published
FROM competition_standing cs
WHERE cs.competition_id = :competitionId
SQL, ['competitionId' => $competitionId]);

        $standings = [];

        foreach ($rows as $row) {
            /** @var array{id: string, name: string, slug: string, published: bool} $row */
            $standings[$row['name']] = ['id' => $row['id'], 'slug' => $row['slug'], 'published' => $row['published']];
        }

        return $standings;
    }

    /**
     * @return array<string, array{id: string, token: string}> by display name
     */
    public function displays(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT cd.id, cd.name, cd.token
FROM competition_display cd
WHERE cd.competition_id = :competitionId
SQL, ['competitionId' => $competitionId]);

        $displays = [];

        foreach ($rows as $row) {
            /** @var array{id: string, name: string, token: string} $row */
            $displays[$row['name']] = ['id' => $row['id'], 'token' => $row['token']];
        }

        return $displays;
    }

    /**
     * The event's active participants the demo imported, by the external id it gave them.
     *
     * @return array<string, string> external id => participant id
     */
    public function participantsByExternalId(string $competitionId): array
    {
        $rows = $this->database->fetchAllKeyValue(<<<SQL
SELECT cp.external_id, cp.id
FROM competition_participant cp
WHERE cp.competition_id = :competitionId
  AND cp.external_id IS NOT NULL
  AND cp.deleted_at IS NULL
SQL, ['competitionId' => $competitionId]);

        $participants = [];

        foreach ($rows as $externalId => $id) {
            $participants[(string) $externalId] = is_string($id) ? $id : '';
        }

        return $participants;
    }

    /**
     * The round's start (UTC) and reveal delay - what a secret puzzle's automatic reveal is computed from.
     *
     * @return null|array{starts_at: string, reveal_delay_minutes: int}
     */
    public function roundReveal(string $roundId): null|array
    {
        $row = $this->database->fetchAssociative('SELECT cr.starts_at, cr.reveal_delay_minutes FROM competition_round cr WHERE cr.id = :id', ['id' => $roundId]);

        if ($row === false) {
            return null;
        }

        /** @var array{starts_at: string, reveal_delay_minutes: int|string} $row */
        return ['starts_at' => $row['starts_at'], 'reveal_delay_minutes' => (int) $row['reveal_delay_minutes']];
    }

    /**
     * The organization of this slug - also a draft or an unapproved one (the command made it).
     */
    public function organizationIdBySlug(string $slug): null|string
    {
        $id = $this->database->fetchOne('SELECT o.id FROM organization o WHERE o.slug = :slug', ['slug' => $slug]);

        return is_string($id) ? $id : null;
    }

    /**
     * The organization's team: its creator and maintainers.
     *
     * @return list<string> player ids
     */
    public function organizationTeam(string $organizationId): array
    {
        $ids = $this->database->fetchFirstColumn(<<<SQL
SELECT o.added_by_player_id FROM organization o WHERE o.id = :id AND o.added_by_player_id IS NOT NULL
UNION
SELECT om.player_id FROM organization_maintainer om WHERE om.organization_id = :id
SQL, ['id' => $organizationId]);

        return array_values(array_filter($ids, is_string(...)));
    }

    public function competitionOrganizationId(string $competitionId): null|string
    {
        $id = $this->database->fetchOne('SELECT c.organization_id FROM competition c WHERE c.id = :id', ['id' => $competitionId]);

        return is_string($id) ? $id : null;
    }

    /**
     * The event's logo in the storage, null = none
     */
    public function competitionLogo(string $competitionId): null|string
    {
        $logo = $this->database->fetchOne('SELECT c.logo FROM competition c WHERE c.id = :id', ['id' => $competitionId]);

        return is_string($logo) ? $logo : null;
    }

    /**
     * The player behind an account - the user id messages of the organiser's web pages carry.
     */
    public function userIdOfPlayer(string $playerId): null|string
    {
        $id = $this->database->fetchOne('SELECT p.user_id FROM player p WHERE p.id = :id', ['id' => $playerId]);

        return is_string($id) ? $id : null;
    }
}
