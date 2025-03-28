<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
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

    /**
     * @return array<CompetitionEvent>
     */
    public function allPast(): array
    {
        $query = 'SELECT * FROM competition WHERE date_from <= :date ORDER BY date_from DESC';
        $now = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'date' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allUpcoming(): array
    {
        $query = 'SELECT * FROM competition WHERE date_from >= :date ORDER BY date_from';
        $now = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'date' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }
}
