<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Message\PruneOnlineMedia;
use SpeedPuzzling\Web\MessageHandler\PruneOnlineMediaHandler;
use SpeedPuzzling\Web\Results\PrunedOnlineMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Evidence past its retention (README §8): periodic photos after the given days, everything else after a year - from
 * its store and its row.
 */
final class PruneOnlineMediaHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private FakeOnlineMediaStorage $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->storage = $container->get(FakeOnlineMediaStorage::class);
    }

    public function testPeriodicPhotosGoAfterAMonthTheRestAfterAYear(): void
    {
        $oldPeriodic = $this->media(OnlineMediaKind::Periodic, '-31 days', OnlineMediaStore::Primary);
        $recentPeriodic = $this->media(OnlineMediaKind::Periodic, '-29 days', OnlineMediaStore::Primary);
        $finishPhoto = $this->media(OnlineMediaKind::Button, '-200 days', OnlineMediaStore::Primary);
        $oldBurst = $this->media(OnlineMediaKind::Burst, '-366 days', OnlineMediaStore::Secondary);

        $pruned = $this->prune(30);

        self::assertSame(1, $pruned->periodic);
        self::assertSame(1, $pruned->evidence);
        self::assertFalse($pruned->hasMore);

        self::assertSame(
            [$recentPeriodic->id->toString(), $finishPhoto->id->toString()],
            array_values(array_filter(
                $this->connection->fetchFirstColumn('SELECT id FROM online_entry_media ORDER BY created_at DESC'),
                static fn (mixed $id): bool => $id !== OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED,
            )),
        );
        self::assertEqualsCanonicalizing([
            'primary/' . $oldPeriodic->objectKey,
            'secondary/' . $oldBurst->objectKey,
        ], $this->storage->deleted());

        // Nothing else to do
        $again = $this->prune(30);
        self::assertSame(0, $again->periodic + $again->evidence);
    }

    public function testABigBacklogGoesInBatches(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO online_entry_media (id, online_entry_id, kind, taken_ms, store, object_key, content_type, declared_bytes, created_at)
             SELECT gen_random_uuid(), :onlineEntry, 'periodic', 1792000000000, 'primary', 'online/backlog/' || n || '.jpg', 'image/jpeg', 35000, NOW() - INTERVAL '40 days'
             FROM generate_series(1, :count) AS n",
            ['onlineEntry' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, 'count' => PruneOnlineMediaHandler::BATCH_SIZE + 1],
        );

        $first = $this->prune(30);
        self::assertSame(PruneOnlineMediaHandler::BATCH_SIZE, $first->periodic);
        self::assertTrue($first->hasMore);

        $second = $this->prune(30);
        self::assertSame(1, $second->periodic);
        self::assertFalse($second->hasMore);

        self::assertSame(0, $this->connection->fetchOne("SELECT COUNT(*) FROM online_entry_media WHERE object_key LIKE 'online/backlog/%'"));
        self::assertCount(PruneOnlineMediaHandler::BATCH_SIZE + 1, $this->storage->deleted());
    }

    private function prune(int $days): PrunedOnlineMedia
    {
        return self::getContainer()->get(PruneOnlineMediaHandler::class)(new PruneOnlineMedia($days));
    }

    private function media(OnlineMediaKind $kind, string $age, OnlineMediaStore $store): OnlineEntryMedia
    {
        $onlineEntry = $this->entityManager->find(OnlineEntry::class, OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        self::assertNotNull($onlineEntry);

        $id = Uuid::uuid7();
        $media = new OnlineEntryMedia(
            id: $id,
            onlineEntry: $onlineEntry,
            kind: $kind,
            takenMs: 1_792_002_832_345,
            store: $store,
            objectKey: 'online/test/' . $id->toString() . '.jpg',
            contentType: 'image/jpeg',
            declaredBytes: 35_000,
            createdAt: new DateTimeImmutable($age),
        );
        $this->entityManager->persist($media);
        $this->entityManager->flush();

        return $media;
    }
}
