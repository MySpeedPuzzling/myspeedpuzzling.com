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
    cr.badge_text_color
FROM competition_round cr
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

        $puzzlesQuery = <<<SQL
SELECT
    crp.round_id,
    crp.hide_until_round_starts,
    crp.hide_mode,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    p.image AS puzzle_image,
    m.name AS manufacturer_name,
    cr.starts_at AS round_starts_at
FROM competition_round_puzzle crp
INNER JOIN puzzle p ON p.id = crp.puzzle_id
INNER JOIN competition_round cr ON cr.id = crp.round_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE crp.round_id IN (:roundIds)
ORDER BY p.name
SQL;

        $puzzleRows = $this->database
            ->executeQuery(
                $puzzlesQuery,
                ['roundIds' => $roundIds],
                ['roundIds' => ArrayParameterType::STRING],
            )
            ->fetchAllAssociative();

        $now = $this->clock->now();
        $revealBuffer = new \DateInterval('PT10M');

        /** @var array<string, array<EditionRoundPuzzle>> $puzzlesByRound */
        $puzzlesByRound = [];
        foreach ($puzzleRows as $row) {
            /** @var array{round_id: string, hide_until_round_starts: bool|string, hide_mode: null|string, puzzle_id: string, puzzle_name: string, pieces_count: int|string, puzzle_image: null|string, manufacturer_name: null|string, round_starts_at: string} $row */
            $hideUntilRoundStarts = $row['hide_until_round_starts'];
            if (is_string($hideUntilRoundStarts)) {
                $hideUntilRoundStarts = $hideUntilRoundStarts === 't' || $hideUntilRoundStarts === '1' || $hideUntilRoundStarts === 'true';
            }

            $hidden = false;
            if ($hideUntilRoundStarts) {
                $roundStartsAt = new DateTimeImmutable($row['round_starts_at']);
                $revealAt = $roundStartsAt->add($revealBuffer);
                $hideMode = $row['hide_mode'] !== null ? PuzzleHideMode::from($row['hide_mode']) : PuzzleHideMode::Entirely;
                $hidden = $now < $revealAt && $hideMode === PuzzleHideMode::Entirely;
            }

            if (!$hidden) {
                $puzzlesByRound[$row['round_id']][] = new EditionRoundPuzzle(
                    puzzleId: $row['puzzle_id'],
                    puzzleName: $row['puzzle_name'],
                    piecesCount: (int) $row['pieces_count'],
                    puzzleImage: $hideUntilRoundStarts && $now < (new DateTimeImmutable($row['round_starts_at']))->add($revealBuffer) ? null : $row['puzzle_image'],
                    manufacturerName: $row['manufacturer_name'],
                    hidden: false,
                );
            }
        }

        return array_map(static function (array $row) use ($puzzlesByRound): EditionRoundDetail {
            /** @var array{id: string, name: string, minutes_limit: int|string, starts_at: string, category: string, badge_background_color: null|string, badge_text_color: null|string} $row */

            return new EditionRoundDetail(
                id: $row['id'],
                name: $row['name'],
                startsAt: new DateTimeImmutable($row['starts_at']),
                minutesLimit: (int) $row['minutes_limit'],
                category: RoundCategory::from($row['category']),
                badgeBackgroundColor: $row['badge_background_color'],
                badgeTextColor: $row['badge_text_color'],
                puzzles: $puzzlesByRound[$row['id']] ?? [],
            );
        }, $rounds);
    }
}
