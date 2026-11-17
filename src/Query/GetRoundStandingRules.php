<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\RoundStandingRules;
use SpeedPuzzling\Web\Value\StandingMissing;

/**
 * The standings that count one round (its event's standings of its category listing it) - what they ask of the round's
 * results tools (RoundStandingRules, world-series-rules.md W1-b/W1-c). One statement; an event without standings
 * answers the defaults.
 */
readonly final class GetRoundStandingRules
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forRound(string $roundId): RoundStandingRules
    {
        if (!Uuid::isValid($roundId)) {
            return new RoundStandingRules();
        }

        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT s.name, s.missing_rule, s.penalise_finished_missing AND s.unfinished_penalty_seconds_per_piece > 0 AS penalised_missing
FROM competition_standing s
INNER JOIN competition_round r ON r.id = :roundId AND r.competition_id = s.competition_id AND r.category = s.category
WHERE s.round_ids @> jsonb_build_array(CAST(:roundKey AS text))
ORDER BY s.position, s.created_at
SQL, ['roundId' => $roundId, 'roundKey' => strtolower($roundId)]);

        $penalisedMissing = false;
        $noShowStandings = [];

        foreach ($rows as $row) {
            /** @var array{name: string, missing_rule: string, penalised_missing: bool} $row */
            $penalisedMissing = $penalisedMissing || $row['penalised_missing'];

            if ($row['missing_rule'] === StandingMissing::AsZeroPieces->value) {
                $noShowStandings[] = $row['name'];
            }
        }

        return new RoundStandingRules($penalisedMissing, $noShowStandings);
    }
}
