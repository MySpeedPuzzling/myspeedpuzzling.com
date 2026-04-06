<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Results\CompetitionEvent;

/**
 * @phpstan-import-type CompetitionEventDatabaseRow from CompetitionEvent
 */
readonly final class GetCompetitionEvents
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function byId(string $competitionId): CompetitionEvent
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $query = 'SELECT * FROM competition WHERE id = :id';

        /** @var false|CompetitionEventDatabaseRow $data */
        $data = $this->database
            ->executeQuery($query, [
                'id' => $competitionId,
            ])
            ->fetchAssociative();

        if ($data === false) {
            throw new CompetitionNotFound();
        }

        return CompetitionEvent::fromDatabaseRow($data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function search(
        string $timePeriod = 'all',
        bool $onlineOnly = false,
        null|string $country = null,
    ): array {
        $date = $this->clock->now()->format('Y-m-d');
        $params = ['date' => $date];

        $cte = <<<'SQL'
        WITH event_classified AS (
            SELECT c.*,
                CASE
                    WHEN c.date_from IS NOT NULL
                        AND :date::date BETWEEN COALESCE(c.date_from, c.date_to)::date AND COALESCE(c.date_to, c.date_from)::date
                        THEN 'live'
                    WHEN COALESCE(c.date_from, c.date_to)::date > :date::date
                        THEN 'upcoming'
                    ELSE 'past'
                END AS event_status,
                COALESCE(c.date_from, c.date_to) AS sort_date
            FROM competition c
            WHERE c.approved_at IS NOT NULL
                AND c.series_id IS NULL
        )
        SQL;

        $whereClauses = [];

        if (in_array($timePeriod, ['live', 'upcoming', 'past'], true)) {
            $whereClauses[] = 'event_status = :status';
            $params['status'] = $timePeriod;
        }

        if ($onlineOnly) {
            $whereClauses[] = 'is_online = true';
        }

        if ($country !== null) {
            $whereClauses[] = 'location_country_code = :country';
            $params['country'] = $country;
        }

        $where = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $orderBy = match ($timePeriod) {
            'live' => 'sort_date ASC NULLS LAST',
            'upcoming' => 'sort_date ASC NULLS LAST',
            'past' => 'sort_date DESC NULLS LAST',
            default => <<<'SQL'
            CASE event_status WHEN 'live' THEN 1 WHEN 'upcoming' THEN 2 WHEN 'past' THEN 3 END ASC,
            CASE WHEN event_status != 'past' THEN sort_date END ASC NULLS LAST,
            CASE WHEN event_status = 'past' THEN sort_date END DESC NULLS LAST
            SQL,
        };

        $query = "{$cte} SELECT * FROM event_classified {$where} ORDER BY {$orderBy}";

        $data = $this->database
            ->executeQuery($query, $params)
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allPast(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
WHERE approved_at IS NOT NULL
    AND series_id IS NULL
    AND (COALESCE(date_to, date_from)::date < :date::date
    OR date_from IS NULL)
ORDER BY date_from DESC;
SQL;
        $date = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'date' => $date->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allUpcoming(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
WHERE approved_at IS NOT NULL
    AND series_id IS NULL
    AND COALESCE(date_from, date_to)::date > :date::date
ORDER BY date_from;
SQL;
        $now = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'date' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allLive(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
WHERE approved_at IS NOT NULL
    AND series_id IS NULL
    AND :date::date
      BETWEEN COALESCE(date_from, date_to)::date
          AND COALESCE(date_to, date_from)::date;
SQL;
        $now = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'date' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function all(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
ORDER BY date_from DESC;
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allUnapproved(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
WHERE approved_at IS NULL
    AND rejected_at IS NULL
ORDER BY created_at DESC;
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allForPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT c.*
FROM competition c
WHERE c.series_id IS NULL
   AND (c.added_by_player_id = :playerId
       OR c.id IN (SELECT competition_id FROM competition_maintainer WHERE player_id = :playerId))
ORDER BY c.created_at DESC NULLS LAST, c.date_from DESC;
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }
}
