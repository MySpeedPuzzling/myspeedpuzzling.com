<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\SnapshotActivityDailySummary;
use SpeedPuzzling\Web\MessageHandler\SnapshotActivityDailySummaryHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The target day is fixed far in the past so fixture players (registered at
 * fixture-load time = today) cannot pollute the new_registrations counts.
 */
final class SnapshotActivityDailySummaryHandlerTest extends KernelTestCase
{
    private const string DAY = '2020-06-15';

    private SnapshotActivityDailySummaryHandler $handler;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(SnapshotActivityDailySummaryHandler::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testAggregatesByLocaleWithMembershipsAndRegistrations(): void
    {
        $registeredOnDay = new DateTimeImmutable(self::DAY . ' 10:00:00', new DateTimeZone('UTC'));
        $registeredEarlier = new DateTimeImmutable('2020-01-01 10:00:00', new DateTimeZone('UTC'));

        // cs: two active players, one with a live membership, one registered on the day
        $csActiveMember = $this->createPlayer('cs', $registeredOnDay);
        $csActiveExpired = $this->createPlayer('cs', $registeredEarlier);
        // en: one active player, no membership
        $enActive = $this->createPlayer('en', $registeredEarlier);
        // no locale: registered on the day but NOT active - registrations only
        $this->createPlayer(null, $registeredOnDay);

        $now = new DateTimeImmutable();
        $this->entityManager->persist(new Membership(
            id: Uuid::uuid7(),
            player: $csActiveMember,
            createdAt: $now,
            grantedUntil: $now->modify('+1 month'),
        ));
        $this->entityManager->persist(new Membership(
            id: Uuid::uuid7(),
            player: $csActiveExpired,
            createdAt: $now,
            grantedUntil: $now->modify('-1 month'),
        ));
        $this->entityManager->flush();

        $this->insertActivity($csActiveMember, self::DAY);
        $this->insertActivity($csActiveExpired, self::DAY);
        $this->insertActivity($enActive, self::DAY);

        $rows = ($this->handler)(new SnapshotActivityDailySummary(self::DAY));

        self::assertSame(3, $rows);
        self::assertSame(['active_players' => 2, 'active_members' => 1, 'new_registrations' => 1], $this->fetchSummary('cs'));
        self::assertSame(['active_players' => 1, 'active_members' => 0, 'new_registrations' => 0], $this->fetchSummary('en'));
        self::assertSame(['active_players' => 0, 'active_members' => 0, 'new_registrations' => 1], $this->fetchSummary('unknown'));
    }

    public function testRerunReplacesTheDayIdempotently(): void
    {
        $player = $this->createPlayer('fr', new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC')));
        $this->insertActivity($player, self::DAY);

        ($this->handler)(new SnapshotActivityDailySummary(self::DAY));
        ($this->handler)(new SnapshotActivityDailySummary(self::DAY));

        /** @var int|string $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM activity_daily_summary WHERE day = :day',
            ['day' => self::DAY],
        );

        self::assertSame(1, (int) $count);
        self::assertSame(['active_players' => 1, 'active_members' => 0, 'new_registrations' => 0], $this->fetchSummary('fr'));
    }

    private function createPlayer(null|string $locale, DateTimeImmutable $registeredAt): Player
    {
        $player = new Player(
            Uuid::uuid7(),
            'ADSN' . bin2hex(random_bytes(2)),
            'msp|' . bin2hex(random_bytes(8)),
            sprintf('snapshot+%s@example.com', bin2hex(random_bytes(4))),
            null,
            $registeredAt,
        );

        if ($locale !== null) {
            $player->changeLocale($locale);
        }

        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    private function insertActivity(Player $player, string $day): void
    {
        $this->connection->insert('player_activity_day', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $player->id->toString(),
            'day' => $day,
            'first_seen_at' => $day . ' 08:00:00+00:00',
        ]);
    }

    /**
     * @return array{active_players: int, active_members: int, new_registrations: int}
     */
    private function fetchSummary(string $locale): array
    {
        /** @var false|array{active_players: int|string, active_members: int|string, new_registrations: int|string} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT active_players, active_members, new_registrations
             FROM activity_daily_summary WHERE day = :day AND locale = :locale',
            ['day' => self::DAY, 'locale' => $locale],
        );

        self::assertNotFalse($row, sprintf('Expected a summary row for locale %s', $locale));

        return [
            'active_players' => (int) $row['active_players'],
            'active_members' => (int) $row['active_members'],
            'new_registrations' => (int) $row['new_registrations'],
        ];
    }
}
