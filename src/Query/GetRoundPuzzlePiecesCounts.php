<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The piece count of every puzzle of some rounds, hidden ones included - for the organiser tools only (organisers and
 * referees get it like the round's `piecesCount` today, R-review-and-plan.md §1.1). Never for a public surface: there
 * RoundPuzzlesVisibleNow says what may be shown.
 */
readonly final class GetRoundPuzzlePiecesCounts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $roundIds
     * @return array<string, int> by competition_round_puzzle.id
     */
    public function ofRounds(array $roundIds): array
    {
        if ($roundIds === []) {
            return [];
        }

        /** @var list<array{id: string, pieces_count: int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT crp.id, p.pieces_count FROM competition_round_puzzle crp INNER JOIN puzzle p ON p.id = crp.puzzle_id WHERE crp.round_id IN (:roundIds)',
            ['roundIds' => $roundIds],
            ['roundIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['id']] = (int) $row['pieces_count'];
        }

        return $counts;
    }
}
