<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The "Part of a series" choices of the creation wizard's backfill path (creation-wizard.md "Backfill"): the series the
 * player may add a date to (CompetitionPermissions::editableSeriesIds()), never a rejected one - their own, so a
 * pending or draft one is offered too.
 */
readonly final class GetPastEventSeriesChoices
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $editableSeriesIds
     * @return array<string, string> id => name, by name
     */
    public function of(array $editableSeriesIds): array
    {
        if ($editableSeriesIds === []) {
            return [];
        }

        /** @var list<array{id: string, name: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT cs.id, cs.name FROM competition_series cs WHERE cs.id IN (:ids) AND cs.rejected_at IS NULL ORDER BY cs.name, cs.id',
            ['ids' => $editableSeriesIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $choices = [];

        foreach ($rows as $row) {
            $choices[$row['id']] = $row['name'];
        }

        return $choices;
    }
}
