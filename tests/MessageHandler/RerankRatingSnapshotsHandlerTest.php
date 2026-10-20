<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RerankRatingSnapshots;
use SpeedPuzzling\Web\MessageHandler\RerankRatingSnapshotsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The stored places ranked again by the ladder's rule (docs/features/msp-rating-history.md): ties share the better
 * place, private and opted-out players are not on the ladder, an unrated row has no place.
 */
final class RerankRatingSnapshotsHandlerTest extends KernelTestCase
{
    public function testOneDayIsRankedLikeTheLadder(): void
    {
        self::bootKernel();
        $connection = $this->connection();
        $connection->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);

        // The old rule's places: ties took the worse place, the opted-out player counted, unrated = 0
        $this->snapshot('2026-06-08', PlayerFixture::PLAYER_ADMIN, 1.5, 2);
        $this->snapshot('2026-06-08', PlayerFixture::PLAYER_REGULAR, 1.4, 4);
        $this->snapshot('2026-06-08', PlayerFixture::PLAYER_WITH_FAVORITES, 1.4, 4);
        $this->snapshot('2026-06-08', PlayerFixture::PLAYER_WITH_STRIPE, 1.6, 1);
        $this->snapshot('2026-06-08', PlayerFixture::PLAYER_PRIVATE, 1.7, 1);
        // Another day stays as it is
        $this->snapshot('2026-06-09', PlayerFixture::PLAYER_REGULAR, 1.4, 9);

        $result = $this->handler()(new RerankRatingSnapshots(new DateTimeImmutable('2026-06-08')));

        $expected = [
            PlayerFixture::PLAYER_ADMIN => 1,
            PlayerFixture::PLAYER_REGULAR => 2,
            PlayerFixture::PLAYER_WITH_FAVORITES => 2,
            PlayerFixture::PLAYER_WITH_STRIPE => null,
            PlayerFixture::PLAYER_PRIVATE => null,
        ];
        ksort($expected);
        self::assertSame($expected, $this->places('2026-06-08'));
        self::assertSame([PlayerFixture::PLAYER_REGULAR => 9], $this->places('2026-06-09'));
        self::assertSame(3, $result->ranked);
        self::assertSame(2, $result->unranked);

        // Again: nothing left to change
        $again = $this->handler()(new RerankRatingSnapshots(new DateTimeImmutable('2026-06-08')));
        self::assertSame([0, 0], [$again->ranked, $again->unranked]);
    }

    public function testAnUnratedRowLosesItsPlace(): void
    {
        self::bootKernel();
        $this->snapshot('2026-06-08', PlayerFixture::PLAYER_REGULAR, null, 0);

        $this->handler()(new RerankRatingSnapshots(new DateTimeImmutable('2026-06-08')));

        self::assertSame([PlayerFixture::PLAYER_REGULAR => null], $this->places('2026-06-08'));
    }

    private function snapshot(string $day, string $playerId, null|float $rating, null|int $place): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, elo_rank, computed_at) VALUES (gen_random_uuid(), :player, 500, :day, :rating, :place, NOW())',
            ['player' => $playerId, 'day' => $day, 'rating' => $rating, 'place' => $place],
        );
    }

    /**
     * @return array<string, null|int>
     */
    private function places(string $day): array
    {
        /** @var list<array{player_id: string, elo_rank: null|int|string}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT player_id, elo_rank FROM player_rating_snapshot WHERE snapshot_date = :day ORDER BY elo_rating DESC NULLS LAST, player_id',
            ['day' => $day],
        );
        $places = [];

        foreach ($rows as $row) {
            $places[$row['player_id']] = $row['elo_rank'] !== null ? (int) $row['elo_rank'] : null;
        }

        ksort($places);

        return $places;
    }

    private function handler(): RerankRatingSnapshotsHandler
    {
        /** @var RerankRatingSnapshotsHandler $handler */
        $handler = self::getContainer()->get(RerankRatingSnapshotsHandler::class);

        return $handler;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
