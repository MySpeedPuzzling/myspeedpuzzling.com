<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Results\PublicStandingSnapshot;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;

/**
 * The standings part of a display's state (competition toolkit v2 S2, displays-standings.md §3.2): for every shown
 * `standings` element the published standing it shows, as a guest sees it (PublicStandingSnapshots - published rounds
 * of a published standing, private players masked, no viewer, so no blocklist: the displays' documented exemption).
 *
 * - `auto` = the published standing whose rounds contain the shown round, else the first published one of the round's
 *   category, else the first published one (by position); none → null (the element's `whenEmpty`).
 * - An explicit id of another event, an unknown or an unpublished standing → null.
 * - Rows cut to the element's `rows`; a row's `key` is opaque per display (an HMAC keyed with the token, like the
 *   results rows) - no participant, line-up or player id leaves the server, no #code either.
 * - **A standings failure never blanks the timer**: everything sits in one try/catch → every element null + a warning.
 *
 * Statements: warm 0 (snapshot + list cached per generation); cold: the list (1, only for `auto`), a snapshot (≤ 4).
 * No instance state (readonly).
 *
 * @phpstan-type DisplayStandingRow array{key: string, rank: null|int, complete: bool, name: string, members: list<string>, countries: list<string>, totalSeconds: int, totalTimeSeconds: int, totalMissingPieces: int, totalPenaltySeconds: int, roundsScored: int, perRound: list<array{status: string, scoreSeconds: null|int, missingPieces: int}>}
 * @phpstan-type DisplayStanding array{name: string, category: string, provisional: bool, final: bool, roundsCounted: int, roundsTotal: int, rounds: list<array{name: string, counted: bool}>, rows: list<DisplayStandingRow>}
 */
final readonly class DisplayStandings
{
    public function __construct(
        private PublicStandingSnapshots $snapshots,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $design the display's normalised design
     * @param null|array{id: string, category: string} $round the round the display shows now
     * @return null|array<string, null|DisplayStanding> element id => its standing; null = no shown standings element
     */
    public function forDisplay(array $design, string $competitionId, null|array $round, string $token): null|array
    {
        $demand = DisplayDesignSanitizer::standingsDemand($design);

        if ($demand === []) {
            return null;
        }

        try {
            return $this->standings($demand, strtolower($competitionId), $round, $token);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not read the standings of a display - shown empty', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);

            return array_map(static fn (): null => null, $demand);
        }
    }

    /**
     * @param array<string, array{standingId: string, rows: int}> $demand
     * @param null|array{id: string, category: string} $round
     * @return array<string, null|DisplayStanding>
     */
    private function standings(array $demand, string $competitionId, null|array $round, string $token): array
    {
        $auto = null;
        $standings = [];
        /** @var array<string, null|PublicStandingSnapshot> $snapshots */
        $snapshots = [];

        foreach ($demand as $elementId => $element) {
            $standingId = $element['standingId'];

            if ($standingId === 'auto') {
                $auto ??= $this->autoPick($competitionId, $round);
                $standingId = $auto;
            }

            if ($standingId === null) {
                $standings[$elementId] = null;

                continue;
            }

            if (!array_key_exists($standingId, $snapshots)) {
                $snapshot = $this->snapshots->forGuest($standingId);
                // Another event's standing is never shown, however its id got into the design
                $snapshots[$standingId] = $snapshot !== null && $snapshot->isPublished() && $snapshot->competitionId() === $competitionId
                    ? $snapshot
                    : null;
            }

            $snapshot = $snapshots[$standingId];
            $standings[$elementId] = $snapshot !== null ? self::shape($snapshot, $element['rows'], $token) : null;
        }

        return $standings;
    }

    /**
     * @param null|array{id: string, category: string} $round
     */
    private function autoPick(string $competitionId, null|array $round): null|string
    {
        $published = array_values(array_filter(
            $this->snapshots->listFor($competitionId),
            static fn (array $standing): bool => $standing['published'],
        ));
        usort($published, static fn (array $a, array $b): int => $a['position'] <=> $b['position'] ?: strcmp($a['id'], $b['id']));

        if ($published === []) {
            return null;
        }

        if ($round !== null) {
            $roundId = strtolower($round['id']);

            foreach ($published as $standing) {
                if (in_array($roundId, array_map(strtolower(...), $standing['roundIds']), true)) {
                    return $standing['id'];
                }
            }

            foreach ($published as $standing) {
                if ($standing['category'] === $round['category']) {
                    return $standing['id'];
                }
            }
        }

        return $published[0]['id'];
    }

    /**
     * @return DisplayStanding
     */
    private static function shape(PublicStandingSnapshot $snapshot, int $rows, string $token): array
    {
        $data = $snapshot->toArray();
        $roundIds = array_map(static fn (array $round): string => strtolower($round['id']), $data['rounds']);

        return [
            'name' => $data['name'],
            'category' => $data['category'],
            'provisional' => $data['provisional'],
            'final' => !$data['provisional'],
            'roundsCounted' => $data['roundsCounted'],
            'roundsTotal' => $data['roundsTotal'],
            'rounds' => array_map(static fn (array $round): array => [
                'name' => $round['name'],
                'counted' => $round['counted'],
            ], $data['rounds']),
            'rows' => array_map(static fn (array $row): array => [
                // Opaque per display (keyed by its token): stable for DOM diffing, says nothing about the entrant
                'key' => substr(hash_hmac('sha256', 'standing:' . $row['key'], $token), 0, 16),
                'rank' => $row['rank'],
                'complete' => $row['complete'],
                'name' => $row['name'],
                // A pair's / team's people; one person has none (their name is the row's)
                'members' => count($row['entrants']) > 1
                    ? array_map(static fn (array $entrant): string => $entrant['name'], $row['entrants'])
                    : [],
                'countries' => $row['countries'],
                'totalSeconds' => $row['totalSeconds'],
                'totalTimeSeconds' => $row['totalTimeSeconds'],
                'totalMissingPieces' => $row['totalMissingPieces'],
                'totalPenaltySeconds' => $row['totalPenaltySeconds'],
                'roundsScored' => $row['roundsScored'],
                // Statuses as the calculator gives them - the renderer shows a score it does not know by its seconds
                'perRound' => self::perRound($row['perRound'], $roundIds),
            ], array_slice($data['rows'], 0, $rows)),
        ];
    }

    /**
     * One score per round of the standing, in the rounds' order (a round without a score of the entrant = not counted).
     *
     * @param list<array{roundId: string, status: string, scoreSeconds: null|int, missingPieces: int, ...}> $scores
     * @param list<string> $roundIds
     * @return list<array{status: string, scoreSeconds: null|int, missingPieces: int}>
     */
    private static function perRound(array $scores, array $roundIds): array
    {
        $byRound = [];

        foreach ($scores as $score) {
            $byRound[strtolower($score['roundId'])] = $score;
        }

        return array_map(static fn (string $roundId): array => isset($byRound[$roundId]) ? [
            'status' => $byRound[$roundId]['status'],
            'scoreSeconds' => $byRound[$roundId]['scoreSeconds'],
            'missingPieces' => $byRound[$roundId]['missingPieces'],
        ] : ['status' => 'not_counted', 'scoreSeconds' => null, 'missingPieces' => 0], $roundIds);
    }
}
