<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\OfficialRoundEntrants;
use SpeedPuzzling\Web\Results\PublishedRoundEntrant;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * The one place that turns official round entries into what the public may see (competition toolkit A5,
 * A-round-formats.md §3.4, R-review-and-plan.md §2.3) - the public round page (GetPublishedRoundResults), the
 * competitor pages and the standings read entries only through it, so they never disagree:
 *
 * - **Published rounds only** (`results_published_at` set, checked in the statement itself) and only entries with a
 *   ranked result - did not start and no result yet are the organiser's. People removed from the event
 *   (soft-deleted participants) are left out, like in the organiser's tools.
 * - Ranked over every such entry with OfficialResultsRanking, THEN the rows of players hidden from the viewer
 *   (HiddenPlayers) are dropped without renumbering (docs/features/player-blocklist.md): a solo row of a hidden
 *   player; a pair/team with a hidden linked member unless the viewer is a linked member too - main's group rule.
 * - Names are the organiser's participant names. A linked player carries their profile (`playerId`, avatar, country)
 *   only when the viewer may see them; a private player the viewer may not see (PrivateProfileAccess) keeps the
 *   organiser's name, nothing of the profile.
 * - A `pick` round's per-puzzle record (`result_puzzles`) comes along as stored - ids of round puzzles only; what of
 *   them a page may name is RoundPuzzlesVisibleNow's business (PublishedEntryPuzzle::listOf()).
 *
 * Drafts are the caller's: every page reading it answers 404 for an event that is not public first.
 *
 * At most two statements for any number of rounds - one for the solo rounds, one for the pair/team rounds (bringing
 * their members along as JSON); a single round runs one.
 *
 * @phpstan-type Row array{round_id: string, ref: RoundEntryRef, result: RoundEntryResult, missing_pieces: null|int, puzzles: null|RoundEntryPuzzles, qualified: bool, team_name: null|string, entrants: list<PublishedRoundEntrant>, participant_ids: list<string>, round_puzzles_count: int, viewer_has_entry: bool, viewer_name: null|string, viewer_country: null|string}
 */
readonly final class OfficialEntrantsView
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    public function forRound(string $roundId, RoundCategory $category, null|string $viewerPlayerId): OfficialRoundEntrants
    {
        return $this->forRounds([$roundId => $category], $viewerPlayerId)[$roundId];
    }

    /**
     * @param array<string, RoundCategory> $rounds round id => its category (which table holds its entries)
     * @param bool $keepHidden the public standings (competition toolkit A7) only: the rows hidden from the viewer stay,
     *     marked `hiddenFromViewer` - a standing sums every entrant first and drops the hidden rows after its own ranking
     * @param bool $asGuest what a guest sees, whoever is signed in (competition toolkit v2 S1, the cached and shared
     *     guest snapshot of a standing - PublicStandingSnapshots): no viewer, nobody hidden (the blocklist is ignored),
     *     nobody unmasked by an allow list (the raw `is_private` setting)
     * @return array<string, OfficialRoundEntrants> every requested round id - one that is not published or has nothing
     *     ranked comes empty (isEmpty())
     */
    public function forRounds(array $rounds, null|string $viewerPlayerId, bool $keepHidden = false, bool $asGuest = false): array
    {
        $viewerPlayerId = $viewerPlayerId !== null && $asGuest === false ? strtolower($viewerPlayerId) : null;
        $soloIds = [];
        $teamIds = [];

        foreach ($rounds as $roundId => $category) {
            if ($category === RoundCategory::Solo) {
                $soloIds[] = (string) $roundId;
            } else {
                $teamIds[] = (string) $roundId;
            }
        }

        /** @var array<string, list<Row>> $rowsByRound */
        $rowsByRound = [];

        // A guest's view reads the raw setting: the allow list of whoever is signed in must never reach a shared answer
        $isPrivate = $asGuest ? 'player.is_private' : $this->privateProfileAccess->sqlIsPrivate('player');

        foreach ([...$this->people($soloIds, $viewerPlayerId, $isPrivate), ...$this->teams($teamIds, $viewerPlayerId, $isPrivate)] as $row) {
            $rowsByRound[$row['round_id']][] = $row;
        }

        $result = [];

        foreach (array_keys($rounds) as $roundId) {
            $roundId = (string) $roundId;
            $result[$roundId] = $this->round($roundId, $rowsByRound[strtolower($roundId)] ?? [], $viewerPlayerId, $keepHidden, $asGuest);
        }

        return $result;
    }

    /**
     * @param list<Row> $rows
     */
    private function round(string $roundId, array $rows, null|string $viewerPlayerId, bool $keepHidden, bool $asGuest): OfficialRoundEntrants
    {
        $ranks = OfficialResultsRanking::rank(array_map(
            static fn (array $row): RoundEntryResult => $row['result'],
            $rows,
        ));

        $entries = [];
        $rankedCount = 0;

        foreach ($rows as $index => $row) {
            $rank = $ranks[$index];

            if ($rank !== null) {
                $rankedCount++;
            }

            if ($rank === null) {
                continue;
            }

            // Hidden AFTER ranking - the places of everybody else stay the official ones
            $hidden = $asGuest === false && $this->isHiddenFromViewer($row['entrants'], $viewerPlayerId);

            if ($hidden && $keepHidden === false) {
                continue;
            }

            $entries[] = new PublishedRoundEntry(
                ref: $row['ref'],
                rank: $rank,
                result: $row['result'],
                qualified: $row['qualified'],
                isTeam: $row['ref']->isTeam(),
                teamName: $row['team_name'],
                entrants: $row['entrants'],
                isViewers: self::isViewers($row['entrants'], $viewerPlayerId),
                puzzles: $row['puzzles'],
                participantIds: $row['participant_ids'],
                hiddenFromViewer: $hidden,
                missingPieces: $row['missing_pieces'],
            );
        }

        usort($entries, static fn (PublishedRoundEntry $a, PublishedRoundEntry $b): int => $a->rank <=> $b->rank
            ?: strcasecmp(self::sortName($a), self::sortName($b))
            ?: strcmp($a->ref->id, $b->ref->id));

        return new OfficialRoundEntrants(
            roundId: $roundId,
            entries: $entries,
            rankedCount: $rankedCount,
            roundPuzzlesCount: $rows[0]['round_puzzles_count'] ?? 0,
            viewerHasEntry: $rows[0]['viewer_has_entry'] ?? false,
            viewerName: $rows[0]['viewer_name'] ?? null,
            viewerCountry: $rows[0]['viewer_country'] ?? null,
        );
    }

    /**
     * @param list<string> $roundIds
     * @return list<Row>
     */
    private function people(array $roundIds, null|string $viewerPlayerId, string $isPrivate): array
    {
        if ($roundIds === []) {
            return [];
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    cpr.round_id,
    cpr.id,
    cpr.result_seconds,
    cpr.result_pieces_placed,
    cpr.result_missing_pieces,
    cpr.result_puzzles,
    cpr.qualified_at IS NOT NULL AS qualified,
    cp.id AS participant_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    player.id AS player_id,
    player.code AS player_code,
    player.country AS player_country,
    player.avatar AS player_avatar,
    {$isPrivate} AS player_is_private,
    (SELECT COUNT(*) FROM competition_round_puzzle crp WHERE crp.round_id = cpr.round_id) AS round_puzzles_count,
    {$this->sqlViewer('cpr', false)}
FROM competition_participant_round cpr
INNER JOIN competition_round official_round ON official_round.id = cpr.round_id AND official_round.results_published_at IS NOT NULL
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
LEFT JOIN player ON player.id = cp.player_id
WHERE cpr.round_id IN (:roundIds)
    AND (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL)
SQL,
            ['roundIds' => $roundIds, 'viewerId' => $viewerPlayerId],
            ['roundIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $entries = [];

        foreach ($rows as $row) {
            /** @var array{round_id: string, id: string, result_seconds: null|int, result_pieces_placed: null|int, result_missing_pieces: null|int, result_puzzles: null|string, qualified: bool, participant_id: string, participant_name: string, participant_country: null|string, player_id: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: null|bool, round_puzzles_count: int|string, viewer_has_entry: bool, viewer_name: null|string, viewer_country: null|string} $row */
            $entries[] = [
                'round_id' => strtolower($row['round_id']),
                'ref' => RoundEntryRef::participantRound($row['id']),
                'result' => RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], false),
                'missing_pieces' => $row['result_seconds'] !== null ? $row['result_missing_pieces'] : null,
                'puzzles' => RoundEntryPuzzles::fromStored($row['result_puzzles']),
                'qualified' => $row['qualified'],
                'team_name' => null,
                'participant_ids' => [strtolower($row['participant_id'])],
                'entrants' => [self::entrant($row['participant_name'], $row['participant_country'], $row['player_id'], $row['player_code'], $row['player_country'], $row['player_avatar'], $row['player_is_private'] === true, $viewerPlayerId)],
                'round_puzzles_count' => (int) $row['round_puzzles_count'],
                'viewer_has_entry' => $row['viewer_has_entry'],
                'viewer_name' => $row['viewer_name'],
                'viewer_country' => $row['viewer_country'],
            ];
        }

        return $entries;
    }

    /**
     * @param list<string> $roundIds
     * @return list<Row>
     */
    private function teams(array $roundIds, null|string $viewerPlayerId, string $isPrivate): array
    {
        if ($roundIds === []) {
            return [];
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    ct.round_id,
    ct.id,
    ct.name,
    ct.result_seconds,
    ct.result_pieces_placed,
    ct.result_missing_pieces,
    ct.result_puzzles,
    ct.qualified_at IS NOT NULL AS qualified,
    (SELECT COUNT(*) FROM competition_round_puzzle crp WHERE crp.round_id = ct.round_id) AS round_puzzles_count,
    {$this->sqlViewer('ct', true)},
    (
        SELECT json_agg(json_build_object(
            'participant_id', cp.id,
            'name', cp.name,
            'country', cp.country,
            'player_id', player.id,
            'player_code', player.code,
            'player_country', player.country,
            'player_avatar', player.avatar,
            'player_is_private', {$isPrivate}
        ) ORDER BY cp.name, cp.id)
        FROM competition_participant_round cpr
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
        LEFT JOIN player ON player.id = cp.player_id
        WHERE cpr.team_id = ct.id
    ) AS members
FROM competition_team ct
INNER JOIN competition_round official_round ON official_round.id = ct.round_id AND official_round.results_published_at IS NOT NULL
WHERE ct.round_id IN (:roundIds)
    AND (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL)
SQL,
            ['roundIds' => $roundIds, 'viewerId' => $viewerPlayerId],
            ['roundIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $entries = [];

        foreach ($rows as $row) {
            /** @var array{round_id: string, id: string, name: null|string, result_seconds: null|int, result_pieces_placed: null|int, result_missing_pieces: null|int, result_puzzles: null|string, qualified: bool, round_puzzles_count: int|string, members: null|string, viewer_has_entry: bool, viewer_name: null|string, viewer_country: null|string} $row */
            /** @var list<array{participant_id: string, name: string, country: null|string, player_id: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: null|bool}> $members */
            $members = $row['members'] !== null ? json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR) : [];

            $entries[] = [
                'round_id' => strtolower($row['round_id']),
                'ref' => RoundEntryRef::team($row['id']),
                'result' => RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], false),
                'missing_pieces' => $row['result_seconds'] !== null ? $row['result_missing_pieces'] : null,
                'puzzles' => RoundEntryPuzzles::fromStored($row['result_puzzles']),
                'qualified' => $row['qualified'],
                'team_name' => $row['name'],
                'participant_ids' => array_map(static fn (array $member): string => strtolower($member['participant_id']), $members),
                'entrants' => array_map(
                    static fn (array $member): PublishedRoundEntrant => self::entrant($member['name'], $member['country'], $member['player_id'], $member['player_code'], $member['player_country'], $member['player_avatar'], $member['player_is_private'] === true, $viewerPlayerId),
                    $members,
                ),
                'round_puzzles_count' => (int) $row['round_puzzles_count'],
                'viewer_has_entry' => $row['viewer_has_entry'],
                'viewer_name' => $row['viewer_name'],
                'viewer_country' => $row['viewer_country'],
            ];
        }

        return $entries;
    }

    /**
     * Columns about the viewer (the same on every row of a round): linked to any entry of the row's round - did not
     * start and no result yet included, in a pair/team round only as a member of a pair/team - and their name and
     * country.
     */
    private function sqlViewer(string $entryAlias, bool $teams): string
    {
        $inTeam = $teams ? 'AND viewer_entry.team_id IS NOT NULL' : '';

        return <<<SQL
    EXISTS (
        SELECT 1
        FROM competition_participant_round viewer_entry
        INNER JOIN competition_participant viewer_participant ON viewer_participant.id = viewer_entry.participant_id AND viewer_participant.deleted_at IS NULL
        WHERE viewer_entry.round_id = {$entryAlias}.round_id
            AND viewer_participant.player_id = CAST(:viewerId AS UUID)
            {$inTeam}
    ) AS viewer_has_entry,
    (SELECT viewer.name FROM player viewer WHERE viewer.id = CAST(:viewerId AS UUID)) AS viewer_name,
    (SELECT viewer.country FROM player viewer WHERE viewer.id = CAST(:viewerId AS UUID)) AS viewer_country
SQL;
    }

    /**
     * One person of an entry as a public page may show them - the masking rule above, shared with the competitor
     * pages (GetCompetitorPage), which show the entrant of rounds without a ranked result too.
     */
    public static function entrant(
        string $name,
        null|string $country,
        null|string $playerId,
        null|string $playerCode,
        null|string $playerCountry,
        null|string $playerAvatar,
        bool $playerIsPrivate,
        null|string $viewerPlayerId,
    ): PublishedRoundEntrant {
        $playerId = $playerId !== null ? strtolower($playerId) : null;
        // A private player stays the organiser's name only - unless it is the viewer, or they let the viewer see them
        $visible = $playerId !== null && ($playerIsPrivate === false || $playerId === $viewerPlayerId);

        return new PublishedRoundEntrant(
            playerName: $name,
            playerCountry: CountryCode::fromCode($country ?? ($visible ? $playerCountry : null)),
            playerId: $visible ? $playerId : null,
            playerAvatar: $visible ? $playerAvatar : null,
            linkedPlayerId: $playerId,
            linkedPlayerCode: $playerCode !== null ? strtoupper($playerCode) : null,
        );
    }

    /**
     * The blocklist's group rule for one entry (the person, or the members of a pair/team): hidden from the viewer when
     * a linked player is hidden from them - unless the viewer is one of them. Shared with the competitor pages.
     *
     * @param list<PublishedRoundEntrant> $entrants
     */
    public function isHiddenFromViewer(array $entrants, null|string $viewerPlayerId): bool
    {
        if (self::isViewers($entrants, $viewerPlayerId)) {
            return false;
        }

        foreach ($entrants as $entrant) {
            if ($this->hiddenPlayers->isHidden($entrant->linkedPlayerId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<PublishedRoundEntrant> $entrants
     */
    public static function isViewers(array $entrants, null|string $viewerPlayerId): bool
    {
        if ($viewerPlayerId === null) {
            return false;
        }

        foreach ($entrants as $entrant) {
            if ($entrant->linkedPlayerId === $viewerPlayerId) {
                return true;
            }
        }

        return false;
    }

    private static function sortName(PublishedRoundEntry $entry): string
    {
        return $entry->teamName ?? implode(', ', array_map(
            static fn (PublishedRoundEntrant $entrant): string => $entrant->playerName,
            $entry->entrants,
        ));
    }
}
