<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\RoundPuzzlesNow;
use SpeedPuzzling\Web\Results\VisibleRoundPuzzle;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * The one rule for what of a round's puzzles is visible at a moment - the public rule, the same for every viewer
 * (docs/features/competitions-management/toolkit/R-review-and-plan.md §1.5). The event and edition pages
 * (GetEditionRounds) and every competition toolkit surface (judge page, competitor pages, displays, overlays, the
 * editor's preview) read round puzzles only through it; the organiser's own round puzzles page keeps
 * SecretPuzzleAccess.
 *
 * - The round's secret puzzle reveal (CompetitionRoundPuzzle::$hideUntilRoundStarts, its moment from
 *   RoundPuzzleReveal): before it the puzzle is left out entirely, or - PuzzleHideMode::ImageOnly - shown by name
 *   without its picture.
 * - The puzzle's site-wide embargo: hide_until leaves the puzzle out, hide_image_until takes its picture away.
 *
 * One statement for any number of rounds.
 */
readonly final class RoundPuzzlesVisibleNow
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forRound(string $roundId, DateTimeImmutable $now): RoundPuzzlesNow
    {
        return $this->forRounds([$roundId], $now)[$roundId];
    }

    /**
     * @param list<string> $roundIds
     * @return array<string, RoundPuzzlesNow> every requested round id (one without visible puzzles has none)
     */
    public function forRounds(array $roundIds, DateTimeImmutable $now): array
    {
        if ($roundIds === []) {
            return [];
        }

        $nowString = $now->format('Y-m-d H:i:s');

        // The embargo columns are compared in SQL like every other puzzle query does; their moments come along only for
        // nextRevealAtMs
        $query = <<<SQL
SELECT
    crp.id AS round_puzzle_id,
    crp.round_id,
    crp.hide_until_round_starts,
    crp.hide_mode,
    crp.reveal_mode,
    crp.reveal_at,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    CASE
        WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp
        THEN NULL
        ELSE p.image
    END AS puzzle_image,
    CASE
        WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp
        THEN NULL
        ELSE p.image_ratio
    END AS puzzle_image_ratio,
    p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp AS puzzle_image_embargoed,
    CASE WHEN p.hide_image_until > :now::timestamp THEN p.hide_image_until END AS puzzle_image_embargoed_until,
    p.hide_until IS NOT NULL AND p.hide_until > :now::timestamp AS puzzle_embargoed,
    CASE WHEN p.hide_until > :now::timestamp THEN p.hide_until END AS puzzle_embargoed_until,
    m.name AS manufacturer_name,
    cr.starts_at AS round_starts_at,
    cr.reveal_delay_minutes AS round_reveal_delay_minutes
FROM competition_round_puzzle crp
INNER JOIN puzzle p ON p.id = crp.puzzle_id
INNER JOIN competition_round cr ON cr.id = crp.round_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE crp.round_id IN (:roundIds)
ORDER BY p.name
SQL;

        $rows = $this->database
            ->executeQuery(
                $query,
                ['roundIds' => $roundIds, 'now' => $nowString],
                ['roundIds' => ArrayParameterType::STRING],
            )
            ->fetchAllAssociative();

        /** @var array<string, list<VisibleRoundPuzzle>> $puzzlesByRound */
        $puzzlesByRound = [];
        /** @var array<string, int> $nextRevealByRound */
        $nextRevealByRound = [];

        foreach ($rows as $row) {
            /** @var array{round_puzzle_id: string, round_id: string, hide_until_round_starts: bool|string, hide_mode: null|string, reveal_mode: string, reveal_at: null|string, puzzle_id: string, puzzle_name: string, pieces_count: int|string, puzzle_image: null|string, puzzle_image_ratio: null|float|string, puzzle_image_embargoed: bool, puzzle_image_embargoed_until: null|string, puzzle_embargoed: bool, puzzle_embargoed_until: null|string, manufacturer_name: null|string, round_starts_at: string, round_reveal_delay_minutes: int|string} $row */
            $roundId = $row['round_id'];
            $hideUntilRoundStarts = $row['hide_until_round_starts'];
            if (is_string($hideUntilRoundStarts)) {
                $hideUntilRoundStarts = $hideUntilRoundStarts === 't' || $hideUntilRoundStarts === '1' || $hideUntilRoundStarts === 'true';
            }

            $moments = [];
            if ($row['puzzle_embargoed_until'] !== null) {
                $moments[] = new DateTimeImmutable($row['puzzle_embargoed_until']);
            }
            if ($row['puzzle_image_embargoed_until'] !== null) {
                $moments[] = new DateTimeImmutable($row['puzzle_image_embargoed_until']);
            }

            // The round puzzle's one reveal moment (RoundPuzzleReveal) - null = a manual reveal not made yet
            $hidden = false;
            $imageHidden = false;
            if ($hideUntilRoundStarts) {
                $revealAt = RoundPuzzleReveal::from($row['reveal_mode'])->revealAt(
                    new DateTimeImmutable($row['round_starts_at']),
                    (int) $row['round_reveal_delay_minutes'],
                    $row['reveal_at'] !== null ? new DateTimeImmutable($row['reveal_at']) : null,
                );
                $imageHidden = $revealAt === null || $now < $revealAt;
                $hideMode = $row['hide_mode'] !== null ? PuzzleHideMode::from($row['hide_mode']) : PuzzleHideMode::Entirely;
                $hidden = $imageHidden && $hideMode === PuzzleHideMode::Entirely;

                if ($revealAt !== null && $imageHidden) {
                    $moments[] = $revealAt;
                }
            }

            foreach ($moments as $moment) {
                $momentMs = RoundClock::milliseconds($moment);
                $nextRevealByRound[$roundId] = min($nextRevealByRound[$roundId] ?? PHP_INT_MAX, $momentMs);
            }

            if ($hidden || $row['puzzle_embargoed']) {
                continue;
            }

            $puzzlesByRound[$roundId][] = new VisibleRoundPuzzle(
                roundPuzzleId: $row['round_puzzle_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: (int) $row['pieces_count'],
                puzzleImage: $imageHidden ? null : $row['puzzle_image'],
                puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
                manufacturerName: $row['manufacturer_name'],
                imageHidden: $imageHidden || $row['puzzle_image_embargoed'],
            );
        }

        $result = [];
        foreach ($roundIds as $roundId) {
            $result[$roundId] = new RoundPuzzlesNow(
                puzzles: $puzzlesByRound[strtolower($roundId)] ?? [],
                nextRevealAtMs: $nextRevealByRound[strtolower($roundId)] ?? null,
            );
        }

        return $result;
    }
}
