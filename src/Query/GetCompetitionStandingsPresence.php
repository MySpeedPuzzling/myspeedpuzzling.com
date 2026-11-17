<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionStandingsPresent;

/**
 * Does a published standing count a round - and is the round published? Does an event have a published standing?
 * One statement each (competition toolkit v2 S1, displays-standings.md §2.2). Read through CompetitionStandingsPresence,
 * which keeps the answer, never directly from a write path.
 */
readonly final class GetCompetitionStandingsPresence
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function ofRound(string $roundId): CompetitionStandingsPresent
    {
        if (Uuid::isValid($roundId) === false) {
            return CompetitionStandingsPresent::none(null);
        }

        /** @var false|array{competition_id: string, round_published: bool, in_published_standing: bool, any_published: bool} $row */
        $row = $this->database->executeQuery(
            <<<SQL
SELECT
    r.competition_id,
    r.results_published_at IS NOT NULL AS round_published,
    EXISTS (
        SELECT 1 FROM competition_standing s
        WHERE s.competition_id = r.competition_id AND s.published_at IS NOT NULL AND s.round_ids @> to_jsonb(lower(r.id::text))
    ) AS in_published_standing,
    EXISTS (SELECT 1 FROM competition_standing s WHERE s.competition_id = r.competition_id AND s.published_at IS NOT NULL) AS any_published
FROM competition_round r
WHERE r.id = :roundId
SQL,
            ['roundId' => $roundId],
        )->fetchAssociative();

        if ($row === false) {
            return CompetitionStandingsPresent::none(null);
        }

        return new CompetitionStandingsPresent(strtolower($row['competition_id']), (bool) $row['in_published_standing'], (bool) $row['round_published'], (bool) $row['any_published']);
    }

    public function ofCompetition(string $competitionId): CompetitionStandingsPresent
    {
        if (Uuid::isValid($competitionId) === false) {
            return CompetitionStandingsPresent::none(null);
        }

        $any = $this->database->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM competition_standing s WHERE s.competition_id = :competitionId AND s.published_at IS NOT NULL)',
            ['competitionId' => $competitionId],
        );

        return new CompetitionStandingsPresent(strtolower($competitionId), false, false, (bool) $any);
    }
}
