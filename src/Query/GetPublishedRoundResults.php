<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\PublishedRoundEntrant;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Results\PublishedRoundResults;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantNameKey;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OfficialEntryProfileState;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Doctrine\DBAL\Connection;

/**
 * A round's published official results for the public round page (docs/features/competitions-management/official-results.md):
 *
 * - Only entries with a ranked result (finished or unfinished) - did not start and no result yet are the organiser's.
 * - Ranked over every such entry with OfficialResultsRanking, THEN the rows of players hidden from the viewer are
 *   dropped without renumbering (docs/features/player-blocklist.md): a solo row of a hidden player; a pair/team with a
 *   hidden linked member unless the viewer is a linked member too - main's group rule (GetRoundResults).
 * - Names are the organiser's participant names. A linked player links to their profile when the viewer may see them;
 *   a private player the viewer may not see (PrivateProfileAccess) keeps the organiser's name, nothing of the profile.
 * - People removed from the event (soft-deleted participants) are left out, like in the organiser's tools.
 * - "Add to my profile" (OfficialEntryProfileState) for the signed-in viewer: their own entry - or, when the organiser
 *   linked them to no entry of the round at all (did not start and no result yet included), a pair/team nobody is
 *   linked to (the organiser typed names only - the Minnesota case) and an unlinked person whose name is the viewer's
 *   (ParticipantNameKey, the country must not differ) - with a finished result, in a round with exactly one puzzle
 *   that is revealed; offered until the viewer has a time in the round, then "On your profile" (derived, nothing
 *   stored). Every other unlinked row is somebody else's as far as anybody knows: the viewer linked to no entry gets one
 *   line instead, "Is your name here? Connect it" (the event's join flow), when the round has an unlinked row.
 *
 * One statement for the entries (a pair/team round brings its members along as JSON; it also tells whether the viewer
 * is linked to any entry of the round, and their name), one more for the viewer's own times in the round - only when a
 * row could offer "Add to my profile".
 *
 * @phpstan-type Row array{ref: RoundEntryRef, result: RoundEntryResult, qualified: bool, team_name: null|string, entrants: list<PublishedRoundEntrant>, round_puzzles_count: int, viewer_has_entry: bool, viewer_name: null|string, viewer_country: null|string}
 */
readonly final class GetPublishedRoundResults
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * SQL condition: the round behind `$roundAlias` (a competition_round alias) shows official results publicly -
     * published, with at least one ranked result. The one rule of the event pages, the result counts and the sitemap.
     */
    public static function sqlShowsOfficialResults(string $roundAlias): string
    {
        return "({$roundAlias}.results_published_at IS NOT NULL AND (" . self::sqlRankedEntriesCount($roundAlias) . ') > 0)';
    }

    /**
     * SQL expression: how many ranked official results the round behind `$roundAlias` has (published or not) - entries
     * of a solo round, pairs/teams of a pair/team round, the same rows forRound() ranks.
     */
    public static function sqlRankedEntriesCount(string $roundAlias): string
    {
        return <<<SQL
CASE WHEN {$roundAlias}.category = 'solo' THEN (
        SELECT COUNT(*)
        FROM competition_participant_round official_entry
        INNER JOIN competition_participant official_participant ON official_participant.id = official_entry.participant_id AND official_participant.deleted_at IS NULL
        WHERE official_entry.round_id = {$roundAlias}.id
            AND (official_entry.result_seconds IS NOT NULL OR official_entry.result_pieces_placed IS NOT NULL)
    ) ELSE (
        SELECT COUNT(*)
        FROM competition_team official_team
        WHERE official_team.round_id = {$roundAlias}.id
            AND (official_team.result_seconds IS NOT NULL OR official_team.result_pieces_placed IS NOT NULL)
    ) END
SQL;
    }

    /**
     * Null when the round's results are not published or nothing is ranked - the page then stays exactly as without
     * official results.
     */
    public function forRound(EditionRoundDetail $round, null|string $viewerPlayerId): null|PublishedRoundResults
    {
        if ($round->resultsPublished === false) {
            return null;
        }

        $viewerPlayerId = $viewerPlayerId !== null ? strtolower($viewerPlayerId) : null;
        $rows = $round->category === RoundCategory::Solo
            ? $this->people($round->id, $viewerPlayerId)
            : $this->teams($round->id, $viewerPlayerId);

        if ($rows === []) {
            return null;
        }

        $viewer = [
            'has_entry' => $rows[0]['viewer_has_entry'],
            'name' => $rows[0]['viewer_name'],
            'country' => $rows[0]['viewer_country'],
        ];

        $ranks = OfficialResultsRanking::rank(array_map(
            static fn (array $row): RoundEntryResult => $row['result'],
            $rows,
        ));

        $entries = [];
        $roundPuzzlesCount = 0;
        $rankedCount = 0;

        foreach ($rows as $index => $row) {
            $roundPuzzlesCount = $row['round_puzzles_count'];
            $rank = $ranks[$index];

            if ($rank !== null) {
                $rankedCount++;
            }

            // Hidden AFTER ranking - the places of everybody else stay the official ones
            if ($rank === null || $this->isHiddenFromViewer($row['entrants'], $viewerPlayerId)) {
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
            );
        }

        usort($entries, static fn (PublishedRoundEntry $a, PublishedRoundEntry $b): int => $a->rank <=> $b->rank
            ?: strcasecmp(self::sortName($a), self::sortName($b))
            ?: strcmp($a->ref->id, $b->ref->id));

        // One puzzle in the round, and the viewer sees it - its piece count, and the puzzle a time is logged on
        $onlyPuzzle = $roundPuzzlesCount === 1 && count($round->puzzles) === 1 ? array_values($round->puzzles)[0] : null;
        $profilePuzzleId = $onlyPuzzle !== null && $onlyPuzzle->imageHidden === false ? $onlyPuzzle->puzzleId : null;

        if ($viewerPlayerId !== null && $profilePuzzleId !== null) {
            $entries = $this->withProfileStates($entries, $round->id, $viewerPlayerId, $viewer);
        }

        return new PublishedRoundResults(
            entries: $entries,
            piecesCount: $onlyPuzzle?->piecesCount,
            profilePuzzleId: $profilePuzzleId,
            rankedCount: $rankedCount,
            // Somebody signed in, linked to nothing here, on a round with names nobody is linked to: maybe one is theirs
            offersConnecting: $viewerPlayerId !== null
                && $viewer['has_entry'] === false
                && array_any($entries, static fn (PublishedRoundEntry $entry): bool => array_any(
                    $entry->entrants,
                    static fn (PublishedRoundEntrant $entrant): bool => $entrant->linkedPlayerId === null,
                )),
        );
    }

    /**
     * The round an official entry (a person of a solo round, a pair/team) belongs to, when it is a round of the given
     * competition - with the competition's name.
     *
     * @return null|array{round_id: string, competition_name: string}
     */
    public function roundOfEntry(string $competitionId, RoundEntryRef $ref): null|array
    {
        $table = $ref->isTeam() ? 'competition_team' : 'competition_participant_round';

        /** @var false|array{round_id: string, competition_name: string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT cr.id AS round_id, c.name AS competition_name
FROM {$table} entry
INNER JOIN competition_round cr ON cr.id = entry.round_id
INNER JOIN competition c ON c.id = cr.competition_id
WHERE entry.id = :entryId
    AND c.id = :competitionId
SQL,
            ['entryId' => $ref->id, 'competitionId' => $competitionId],
        );

        return $row === false ? null : $row;
    }

    /**
     * @param list<PublishedRoundEntry> $entries
     * @param array{has_entry: bool, name: null|string, country: null|string} $viewer
     * @return list<PublishedRoundEntry>
     */
    private function withProfileStates(array $entries, string $roundId, string $viewerPlayerId, array $viewer): array
    {
        // The organiser linked the viewer to an entry of the round (ranked or not): that one is theirs. Otherwise a
        // pair/team nobody is linked to may be (the organiser typed names only, team names say), and a person nobody
        // is linked to whose name is the viewer's - never everybody's unlinked row for every visitor
        $candidates = array_filter(
            $entries,
            static fn (PublishedRoundEntry $entry): bool => $entry->result->isFinished()
                && ($entry->isViewers || (
                    $viewer['has_entry'] === false
                    && $entry->hasLinkedPlayer() === false
                    && ($entry->isTeam || self::isViewersName($entry, $viewer))
                )),
        );

        if ($candidates === []) {
            return $entries;
        }

        $viewerTimes = $this->viewerTimesInRound($roundId, $viewerPlayerId);

        return array_map(static function (PublishedRoundEntry $entry) use ($candidates, $viewerTimes): PublishedRoundEntry {
            if (in_array($entry, $candidates, true) === false) {
                return $entry;
            }

            if ($viewerTimes === []) {
                return $entry->withProfileState(OfficialEntryProfileState::Offer);
            }

            // The viewer's own entry is the one on their profile; of the entries nobody is linked to, the one whose
            // time the viewer logged - the rest offer nothing any more
            if ($entry->isViewers || in_array($entry->result->seconds, $viewerTimes, true)) {
                return $entry->withProfileState(OfficialEntryProfileState::OnProfile);
            }

            return $entry;
        }, $entries);
    }

    /**
     * Seconds of every time the viewer has in the round - their own or one they took part in. A time without seconds
     * (unfinished) counts as one too, so the list may hold nulls.
     *
     * @return list<null|int>
     */
    private function viewerTimesInRound(string $roundId, string $viewerPlayerId): array
    {
        /** @var list<null|int|string> $seconds */
        $seconds = $this->database->fetchFirstColumn(
            <<<SQL
SELECT pst.seconds_to_solve
FROM puzzle_solving_time pst
WHERE pst.competition_round_id = :roundId
    AND (
        pst.player_id = :viewerId
        OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:viewerId AS UUID)))
    )
SQL,
            ['roundId' => $roundId, 'viewerId' => $viewerPlayerId],
        );

        return array_map(static fn (null|int|string $value): null|int => $value !== null ? (int) $value : null, $seconds);
    }

    /**
     * @return list<Row>
     */
    private function people(string $roundId, null|string $viewerPlayerId): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cpr.id,
    cpr.result_seconds,
    cpr.result_pieces_placed,
    cpr.qualified_at IS NOT NULL AS qualified,
    cp.name AS participant_name,
    cp.country AS participant_country,
    player.id AS player_id,
    player.code AS player_code,
    player.country AS player_country,
    player.avatar AS player_avatar,
    {$this->privateProfileAccess->sqlIsPrivate('player')} AS player_is_private,
    (SELECT COUNT(*) FROM competition_round_puzzle crp WHERE crp.round_id = :roundId) AS round_puzzles_count,
    {$this->sqlViewer(false)}
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
LEFT JOIN player ON player.id = cp.player_id
WHERE cpr.round_id = :roundId
    AND (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL)
SQL,
            ['roundId' => $roundId, 'viewerId' => $viewerPlayerId],
        );

        $entries = [];

        foreach ($rows as $row) {
            /** @var array{id: string, result_seconds: null|int, result_pieces_placed: null|int, qualified: bool, participant_name: string, participant_country: null|string, player_id: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: null|bool, round_puzzles_count: int|string, viewer_has_entry: bool, viewer_name: null|string, viewer_country: null|string} $row */
            $entries[] = [
                'ref' => RoundEntryRef::participantRound($row['id']),
                'result' => RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], false),
                'qualified' => $row['qualified'],
                'team_name' => null,
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
     * @return list<Row>
     */
    private function teams(string $roundId, null|string $viewerPlayerId): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    ct.id,
    ct.name,
    ct.result_seconds,
    ct.result_pieces_placed,
    ct.qualified_at IS NOT NULL AS qualified,
    (SELECT COUNT(*) FROM competition_round_puzzle crp WHERE crp.round_id = :roundId) AS round_puzzles_count,
    {$this->sqlViewer(true)},
    (
        SELECT json_agg(json_build_object(
            'name', cp.name,
            'country', cp.country,
            'player_id', player.id,
            'player_code', player.code,
            'player_country', player.country,
            'player_avatar', player.avatar,
            'player_is_private', {$this->privateProfileAccess->sqlIsPrivate('player')}
        ) ORDER BY cp.name, cp.id)
        FROM competition_participant_round cpr
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
        LEFT JOIN player ON player.id = cp.player_id
        WHERE cpr.team_id = ct.id
    ) AS members
FROM competition_team ct
WHERE ct.round_id = :roundId
    AND (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL)
SQL,
            ['roundId' => $roundId, 'viewerId' => $viewerPlayerId],
        );

        $entries = [];

        foreach ($rows as $row) {
            /** @var array{id: string, name: null|string, result_seconds: null|int, result_pieces_placed: null|int, qualified: bool, round_puzzles_count: int|string, members: null|string, viewer_has_entry: bool, viewer_name: null|string, viewer_country: null|string} $row */
            /** @var list<array{name: string, country: null|string, player_id: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: null|bool}> $members */
            $members = $row['members'] !== null ? json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR) : [];

            $entries[] = [
                'ref' => RoundEntryRef::team($row['id']),
                'result' => RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], false),
                'qualified' => $row['qualified'],
                'team_name' => $row['name'],
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
     * Columns about the viewer (the same on every row): linked to any entry of the round - did not start and no
     * result yet included, in a pair/team round only as a member of a pair/team - and their name and country.
     */
    private function sqlViewer(bool $teams): string
    {
        $inTeam = $teams ? 'AND viewer_entry.team_id IS NOT NULL' : '';

        return <<<SQL
    EXISTS (
        SELECT 1
        FROM competition_participant_round viewer_entry
        INNER JOIN competition_participant viewer_participant ON viewer_participant.id = viewer_entry.participant_id AND viewer_participant.deleted_at IS NULL
        WHERE viewer_entry.round_id = :roundId
            AND viewer_participant.player_id = CAST(:viewerId AS UUID)
            {$inTeam}
    ) AS viewer_has_entry,
    (SELECT viewer.name FROM player viewer WHERE viewer.id = CAST(:viewerId AS UUID)) AS viewer_name,
    (SELECT viewer.country FROM player viewer WHERE viewer.id = CAST(:viewerId AS UUID)) AS viewer_country
SQL;
    }

    private static function entrant(
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
     * @param list<PublishedRoundEntrant> $entrants
     */
    private function isHiddenFromViewer(array $entrants, null|string $viewerPlayerId): bool
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
    private static function isViewers(array $entrants, null|string $viewerPlayerId): bool
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

    /**
     * The one person of an unlinked solo row has the viewer's name - spelled the way the participant import compares
     * names (ParticipantNameKey) - and no other country.
     *
     * @param array{has_entry: bool, name: null|string, country: null|string} $viewer
     */
    private static function isViewersName(PublishedRoundEntry $entry, array $viewer): bool
    {
        $entrant = $entry->entrants[0] ?? null;

        if ($entrant === null || $viewer['name'] === null || trim($viewer['name']) === '') {
            return false;
        }

        if (ParticipantNameKey::of($entrant->playerName) !== ParticipantNameKey::of($viewer['name'])) {
            return false;
        }

        $viewerCountry = CountryCode::fromCode($viewer['country']);

        return $entrant->playerCountry === null || $viewerCountry === null || $entrant->playerCountry === $viewerCountry;
    }

    private static function sortName(PublishedRoundEntry $entry): string
    {
        return $entry->teamName ?? implode(', ', array_map(
            static fn (PublishedRoundEntrant $entrant): string => $entrant->playerName,
            $entry->entrants,
        ));
    }
}
