<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\TestClock;

/**
 * A standing written straight into the test database (competition toolkit A7) - for the canaries and page tests that
 * need a published standing over a fixture event's rounds without the write path.
 */
final class PublishedStanding
{
    /**
     * @param list<string> $roundIds
     * @return string the standing's id
     */
    public static function insert(Connection $database, string $competitionId, string $slug, string $name, string $category, array $roundIds, bool $published = true): string
    {
        $id = Uuid::uuid7()->toString();

        $database->executeStatement(
            'INSERT INTO competition_standing (id, competition_id, category, name, slug, round_ids, created_at, published_at) VALUES (:id, :competitionId, :category, :name, :slug, :roundIds, ' . TestClock::SQL_NOW . ', ' . ($published ? TestClock::SQL_NOW : 'NULL') . ')',
            [
                'id' => $id,
                'competitionId' => $competitionId,
                'category' => $category,
                'name' => $name,
                'slug' => $slug,
                'roundIds' => json_encode(array_map(strtolower(...), $roundIds), JSON_THROW_ON_ERROR),
            ],
        );

        return $id;
    }
}
