<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\StandingEntrant;

/**
 * A published standing as a guest sees it (competition toolkit v2 S1, displays-standings.md §2.1) - computed once per
 * generation of its event by PublicStandingSnapshots and shared by everybody who reads it (the public JSON, the
 * displays). Plain arrays only, so it is cached as data, never as a PHP object.
 *
 * Rows keep the internal `key` (participant / line-up id) for the display's opaque row keys and a future grouping - it
 * is never rendered: PublicStandingJson is the whitelist of what leaves the server. Statuses are the calculator's values,
 * passed through as they are (a status added later needs no change here).
 *
 * @phpstan-type SnapshotEntrant array{name: string, country: null|string, playerCode: null|string}
 * @phpstan-type SnapshotScore array{roundId: string, status: string, scoreSeconds: null|int, timeSeconds: null|int, missingPieces: int, penaltySeconds: int, rank: null|int, result: null|array{seconds: null|int, piecesPlaced: null|int}}
 * @phpstan-type SnapshotRow array{key: string, rank: null|int, complete: bool, name: string, countries: list<string>, entrants: list<SnapshotEntrant>, totalSeconds: int, totalTimeSeconds: int, totalMissingPieces: int, totalPenaltySeconds: int, roundsScored: int, roundsFinished: int, perRound: list<SnapshotScore>}
 * @phpstan-type SnapshotRound array{id: string, name: string, slug: null|string, minutesLimit: null|int, published: bool, counted: bool}
 * @phpstan-type SnapshotData array{competitionId: string, standingId: string, name: string, slug: string, category: string, mode: string, published: bool, position: int, rules: array{penaltySecondsPerPiece: int, order: string, missing: string, penaliseFinishedMissing: bool}, provisional: bool, roundsCounted: int, roundsTotal: int, eventSlug: null|string, seriesSlug: null|string, rounds: list<SnapshotRound>, rows: list<SnapshotRow>, generatedAtMs: int}
 */
readonly final class PublicStandingSnapshot
{
    /**
     * @param SnapshotData $data
     */
    private function __construct(
        public array $data,
    ) {
    }

    /**
     * @param SnapshotData $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function unpublished(PublicStandingHead $head, int $generatedAtMs): self
    {
        return new self([
            ...self::headData($head),
            'published' => false,
            'provisional' => true,
            'roundsCounted' => 0,
            'roundsTotal' => 0,
            'rounds' => [],
            'rows' => [],
            'generatedAtMs' => $generatedAtMs,
        ]);
    }

    public static function of(PublicStandingHead $head, PublicStandingTable $table, int $generatedAtMs): self
    {
        return new self([
            ...self::headData($head),
            'published' => true,
            'provisional' => $table->isProvisional(),
            'roundsCounted' => $table->roundsCounted(),
            'roundsTotal' => $table->roundsTotal(),
            'rounds' => array_map(static fn (StandingRoundColumn $round): array => [
                'id' => $round->id,
                'name' => $round->name,
                'slug' => $round->slug,
                'minutesLimit' => $round->minutesLimit,
                'published' => $round->published,
                'counted' => $round->counted,
            ], $table->table->rounds),
            'rows' => array_map(static fn (PublicStandingRow $row): array => self::row($row->row), $table->rows),
            'generatedAtMs' => $generatedAtMs,
        ]);
    }

    /**
     * @return SnapshotData
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function competitionId(): string
    {
        return $this->data['competitionId'];
    }

    public function isPublished(): bool
    {
        return $this->data['published'];
    }

    /**
     * @return array{competitionId: string, standingId: string, name: string, slug: string, category: string, mode: string, position: int, rules: array{penaltySecondsPerPiece: int, order: string, missing: string, penaliseFinishedMissing: bool}, eventSlug: null|string, seriesSlug: null|string}
     */
    private static function headData(PublicStandingHead $head): array
    {
        $standing = $head->standing;

        return [
            'competitionId' => strtolower($standing->competitionId),
            'standingId' => strtolower($standing->id),
            'name' => $standing->name,
            'slug' => $standing->slug,
            'category' => $standing->category->value,
            'mode' => $standing->mode->value,
            'position' => $standing->position,
            'rules' => $standing->rules->toArray(),
            'eventSlug' => $head->eventSlug,
            'seriesSlug' => $head->seriesSlug,
        ];
    }

    /**
     * @return SnapshotRow
     */
    private static function row(StandingRow $row): array
    {
        return [
            'key' => $row->key,
            'rank' => $row->rank,
            'complete' => $row->complete,
            'name' => $row->name,
            'countries' => $row->countries,
            // A #code only for a public profile (publicView's guest rule: StandingEntrant::$playerCode is set only then)
            'entrants' => array_map(static fn (StandingEntrant $entrant): array => [
                'name' => $entrant->name,
                'country' => $entrant->country,
                'playerCode' => $entrant->playerId !== null ? $entrant->playerCode : null,
            ], $row->entrants),
            'totalSeconds' => $row->totalSeconds,
            'totalTimeSeconds' => $row->totalTimeSeconds,
            'totalMissingPieces' => $row->totalMissingPieces,
            'totalPenaltySeconds' => $row->totalPenaltySeconds,
            'roundsScored' => $row->roundsScored,
            'roundsFinished' => $row->roundsFinished,
            'perRound' => array_values(array_map(static fn (StandingRoundScore $score): array => [
                'roundId' => strtolower($score->roundId),
                'status' => $score->status->value,
                'scoreSeconds' => $score->scoreSeconds,
                'timeSeconds' => $score->timeSeconds,
                'missingPieces' => $score->missingPieces,
                'penaltySeconds' => $score->penaltySeconds,
                'rank' => $score->rank,
                'result' => $score->result !== null && ($score->result->seconds !== null || $score->result->piecesPlaced !== null)
                    ? ['seconds' => $score->result->seconds, 'piecesPlaced' => $score->result->piecesPlaced]
                    : null,
            ], $row->perRound)),
        ];
    }
}
