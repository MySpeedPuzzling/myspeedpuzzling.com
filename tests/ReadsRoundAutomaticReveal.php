<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * The round's automatic reveal as an organiser's page shows it right now - what a test adding a secret puzzle passes as
 * AddPuzzleToCompetitionRound::$shownAutomaticRevealAt. Read from the database, never from the entity manager, so a
 * round changed by SQL (or by another request) is seen as it is.
 */
trait ReadsRoundAutomaticReveal
{
    private static function automaticRevealOf(string $roundId): DateTimeImmutable
    {
        $connection = self::getContainer()->get(Connection::class);

        /** @var false|array{starts_at: string, reveal_delay_minutes: int} $row */
        $row = $connection->fetchAssociative(
            'SELECT starts_at, reveal_delay_minutes FROM competition_round WHERE id = :id',
            ['id' => $roundId],
        );
        assert($row !== false, sprintf('No round %s.', $roundId));

        $startsAt = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row['starts_at'], $connection->getDatabasePlatform());
        assert($startsAt instanceof DateTimeImmutable);

        return RoundPuzzleReveal::automaticRevealAt($startsAt, $row['reveal_delay_minutes']);
    }
}
