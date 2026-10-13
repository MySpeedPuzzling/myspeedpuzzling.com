<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Every round of the event a round belongs to, with whether it uses online timing (docs/features/online-competitions/)
 * - what the round tools of the organiser pages need (OnlineRoundTools), read once for all the rounds a page lists.
 * One statement.
 */
readonly final class GetRoundsWithOnlineTiming
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, array{competitionId: string, online: bool}> round id (lower case) => its event and whether
     *                                                                     it uses online timing; empty for an unknown round
     */
    public function sameEventAs(string $roundId): array
    {
        if (Uuid::isValid($roundId) === false) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cr.id,
    cr.competition_id,
    online_round.id IS NOT NULL AS online
FROM competition_round cr
LEFT JOIN online_round ON online_round.round_id = cr.id
WHERE cr.competition_id = (
    SELECT this_round.competition_id
    FROM competition_round this_round
    WHERE this_round.id = :roundId
)
SQL,
            ['roundId' => strtolower($roundId)],
        );

        $rounds = [];

        foreach ($rows as $row) {
            /** @var array{id: string, competition_id: string, online: bool} $row */
            $rounds[strtolower($row['id'])] = [
                'competitionId' => strtolower($row['competition_id']),
                'online' => $row['online'],
            ];
        }

        return $rounds;
    }
}
