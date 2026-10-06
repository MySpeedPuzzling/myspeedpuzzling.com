<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * Puzzles a round has revealed (or never hid) whose name the whole site still keeps secret - another round holds them
 * longer. The round's pages leave them out (GetEditionRounds), and nobody can log a time on them yet.
 */
readonly final class GetRoundPuzzlesHeldElsewhere
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> puzzle ids
     */
    public function forRound(string $roundId): array
    {
        if (Uuid::isValid($roundId) === false) {
            return [];
        }

        $hidden = RoundPuzzleReveal::sqlHidden('crp', 'cr');

        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->database->fetchFirstColumn(
            <<<SQL
SELECT crp.puzzle_id
FROM competition_round_puzzle crp
INNER JOIN competition_round cr ON cr.id = crp.round_id
INNER JOIN puzzle p ON p.id = crp.puzzle_id
WHERE crp.round_id = :roundId
    AND NOT {$hidden}
    AND p.hide_until IS NOT NULL
    AND p.hide_until > :now::timestamp
ORDER BY p.hide_until, crp.puzzle_id
SQL,
            [
                'roundId' => $roundId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        return $puzzleIds;
    }
}
