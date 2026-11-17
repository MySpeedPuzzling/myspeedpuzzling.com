<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\AdvancementStepNotFound;
use SpeedPuzzling\Web\Results\AdvancementStepOverview;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;

/**
 * The advancement steps of an event (competition toolkit A8) for the organiser's pages - every reader here is behind
 * COMPETITION_TOOLKIT_MANAGE or COMPETITION_EDIT (the results desk). Nothing public reads them yet (the bracket view is
 * A9).
 */
readonly final class GetCompetitionAdvancementSteps
{
    private const string SELECT = <<<SQL
SELECT id, competition_id, name, source_round_ids, target_round_ids, distribution, target_by_source, qualify_count, qualify_scope, best_of_each_country, position
FROM competition_advancement_step
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<AdvancementStepOverview> by position, then creation
     */
    public function ofCompetition(string $competitionId): array
    {
        if (!Uuid::isValid($competitionId)) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            self::SELECT . "\nWHERE competition_id = :competitionId\nORDER BY position, created_at, id",
            ['competitionId' => $competitionId],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @throws AdvancementStepNotFound
     */
    public function byId(string $stepId): AdvancementStepOverview
    {
        if (!Uuid::isValid($stepId)) {
            throw new AdvancementStepNotFound();
        }

        $row = $this->database->fetchAssociative(self::SELECT . "\nWHERE id = :id", ['id' => $stepId]);

        if ($row === false) {
            throw new AdvancementStepNotFound();
        }

        return self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AdvancementStepOverview
    {
        /** @var array{id: string, competition_id: string, name: null|string, source_round_ids: string, target_round_ids: string, distribution: string, target_by_source: string, qualify_count: null|int, qualify_scope: string, best_of_each_country: null|int, position: int} $row */
        $map = [];
        $decodedMap = json_decode($row['target_by_source'], true, flags: JSON_THROW_ON_ERROR);

        if (is_array($decodedMap)) {
            foreach ($decodedMap as $source => $target) {
                if (is_string($target)) {
                    $map[strtolower((string) $source)] = strtolower($target);
                }
            }
        }

        return new AdvancementStepOverview(
            id: $row['id'],
            competitionId: $row['competition_id'],
            name: $row['name'],
            settings: new AdvancementStepSettings(
                sourceRoundIds: self::ids($row['source_round_ids']),
                targetRoundIds: self::ids($row['target_round_ids']),
                distribution: AdvanceDistribution::from($row['distribution']),
                targetBySource: $map,
                qualifyCount: $row['qualify_count'],
                qualifyScope: AdvancementQualifyScope::from($row['qualify_scope']),
                bestOfEachCountry: $row['best_of_each_country'],
            ),
            position: $row['position'],
        );
    }

    /**
     * @return list<string>
     */
    private static function ids(string $json): array
    {
        $ids = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($ids) ? array_values(array_map(static fn (mixed $id): string => strtolower(is_string($id) ? $id : ''), $ids)) : [];
    }
}
