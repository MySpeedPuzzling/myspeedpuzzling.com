<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\TestClock;

/**
 * An advancement step written straight into the test database (competition toolkit A9) - for the bracket's canaries and
 * page tests that need a declared step over a fixture event's rounds without the write path.
 */
final class DeclaredAdvancementStep
{
    /**
     * @param list<string> $sourceRoundIds
     * @param list<string> $targetRoundIds
     * @param array<string, string> $targetBySource
     * @return string the step's id
     */
    public static function insert(
        Connection $database,
        string $competitionId,
        array $sourceRoundIds,
        array $targetRoundIds,
        string $distribution = 'single',
        null|int $qualifyCount = null,
        array $targetBySource = [],
        int $position = 0,
    ): string {
        $id = Uuid::uuid7()->toString();

        $database->executeStatement(
            'INSERT INTO competition_advancement_step (id, competition_id, name, source_round_ids, target_round_ids, distribution, target_by_source, qualify_count, position, created_at) VALUES (:id, :competitionId, NULL, :sources, :targets, :distribution, :map, :qualifyCount, :position, ' . TestClock::SQL_NOW . ')',
            [
                'id' => $id,
                'competitionId' => $competitionId,
                'sources' => json_encode(array_map(strtolower(...), $sourceRoundIds), JSON_THROW_ON_ERROR),
                'targets' => json_encode(array_map(strtolower(...), $targetRoundIds), JSON_THROW_ON_ERROR),
                'distribution' => $distribution,
                'map' => json_encode(array_change_key_case(array_map(strtolower(...), $targetBySource)) ?: new \stdClass(), JSON_THROW_ON_ERROR),
                'qualifyCount' => $qualifyCount,
                'position' => $position,
            ],
        );

        return $id;
    }
}
