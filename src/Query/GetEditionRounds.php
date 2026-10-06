<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\EditionRoundPuzzle;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class GetEditionRounds
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<EditionRoundDetail>
     */
    public function forCompetition(string $competitionId): array
    {
        $roundsQuery = <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.minutes_limit,
    cr.starts_at,
    cr.category,
    cr.badge_background_color,
    cr.badge_text_color,
    cr.slug,
    cr.results_link,
    cr.timezone,
    c.location_country_code,
    tz_cs.location_country_code AS series_country_code
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN competition_series tz_cs ON tz_cs.id = c.series_id
WHERE cr.competition_id = :competitionId
ORDER BY cr.starts_at
SQL;

        $rounds = $this->database
            ->executeQuery($roundsQuery, ['competitionId' => $competitionId])
            ->fetchAllAssociative();

        if ($rounds === []) {
            return [];
        }

        $roundIds = array_column($rounds, 'id');

        $now = $this->clock->now();
        $nowString = $now->format('Y-m-d H:i:s');

        // Besides the round-level reveal rule below, puzzles also carry platform-wide embargo
        // columns (hide_until / hide_image_until) that every other puzzle query honors. Enforce
        // them here too so an embargoed puzzle attached to a round cannot leak: hide_until drops
        // the whole row, hide_image_until nulls the image.
        $puzzlesQuery = <<<SQL
SELECT
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
    m.name AS manufacturer_name,
    cr.starts_at AS round_starts_at
FROM competition_round_puzzle crp
INNER JOIN puzzle p ON p.id = crp.puzzle_id
INNER JOIN competition_round cr ON cr.id = crp.round_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE crp.round_id IN (:roundIds)
    AND (p.hide_until IS NULL OR p.hide_until <= :now::timestamp)
ORDER BY p.name
SQL;

        $puzzleRows = $this->database
            ->executeQuery(
                $puzzlesQuery,
                ['roundIds' => $roundIds, 'now' => $nowString],
                ['roundIds' => ArrayParameterType::STRING],
            )
            ->fetchAllAssociative();

        /** @var array<string, array<EditionRoundPuzzle>> $puzzlesByRound */
        $puzzlesByRound = [];
        foreach ($puzzleRows as $row) {
            /** @var array{round_id: string, hide_until_round_starts: bool|string, hide_mode: null|string, reveal_mode: string, reveal_at: null|string, puzzle_id: string, puzzle_name: string, pieces_count: int|string, puzzle_image: null|string, puzzle_image_ratio: null|float|string, manufacturer_name: null|string, round_starts_at: string} $row */
            $hideUntilRoundStarts = $row['hide_until_round_starts'];
            if (is_string($hideUntilRoundStarts)) {
                $hideUntilRoundStarts = $hideUntilRoundStarts === 't' || $hideUntilRoundStarts === '1' || $hideUntilRoundStarts === 'true';
            }

            // The round puzzle's one reveal moment (RoundPuzzleReveal) - null = a manual reveal not made yet
            $hidden = false;
            $imageHidden = false;
            if ($hideUntilRoundStarts) {
                $revealAt = RoundPuzzleReveal::from($row['reveal_mode'])->revealAt(
                    new DateTimeImmutable($row['round_starts_at']),
                    $row['reveal_at'] !== null ? new DateTimeImmutable($row['reveal_at']) : null,
                );
                $imageHidden = $revealAt === null || $now < $revealAt;
                $hideMode = $row['hide_mode'] !== null ? PuzzleHideMode::from($row['hide_mode']) : PuzzleHideMode::Entirely;
                $hidden = $imageHidden && $hideMode === PuzzleHideMode::Entirely;
            }

            if (!$hidden) {
                $puzzlesByRound[$row['round_id']][] = new EditionRoundPuzzle(
                    puzzleId: $row['puzzle_id'],
                    puzzleName: $row['puzzle_name'],
                    piecesCount: (int) $row['pieces_count'],
                    puzzleImage: $imageHidden ? null : $row['puzzle_image'],
                    puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
                    manufacturerName: $row['manufacturer_name'],
                    hidden: false,
                );
            }
        }

        // Rounds come ordered by start, so the index is the round's position in the schedule
        return array_map(static function (array $row, int $schedulePosition) use ($puzzlesByRound): EditionRoundDetail {
            /** @var array{id: string, name: string, minutes_limit: int|string, starts_at: string, category: string, badge_background_color: null|string, badge_text_color: null|string, slug: null|string, results_link: null|string, timezone: null|string, location_country_code: null|string, series_country_code: null|string} $row */

            $color = RoundBadgeColor::background($row['badge_background_color'], $schedulePosition);

            return new EditionRoundDetail(
                id: $row['id'],
                name: $row['name'],
                startsAt: new DateTimeImmutable($row['starts_at']),
                minutesLimit: (int) $row['minutes_limit'],
                category: RoundCategory::from($row['category']),
                badgeBackgroundColor: $row['badge_background_color'],
                badgeTextColor: $row['badge_text_color'],
                puzzles: $puzzlesByRound[$row['id']] ?? [],
                color: $color,
                textColor: RoundBadgeColor::text($color),
                slug: $row['slug'],
                resultsLink: $row['results_link'],
                timezone: RoundTimezone::resolve($row['timezone'], $row['location_country_code'], $row['series_country_code']),
            );
        }, $rounds, array_keys($rounds));
    }
}
