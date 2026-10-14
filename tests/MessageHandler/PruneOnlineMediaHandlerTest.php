<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Message\PruneOnlineMedia;
use SpeedPuzzling\Web\MessageHandler\PruneOnlineMediaHandler;
use SpeedPuzzling\Web\Results\PrunedOnlineMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReferenceImages;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaObject;
use SpeedPuzzling\Web\Value\OnlineMediaObjectList;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\PresignedUpload;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Evidence past its retention (README §8): periodic photos after the given days, everything else after a year - from
 * its store and its row, a page at a time, and never stuck on an object the store will not delete.
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
        $oldPeriodic = $this->media(OnlineMediaKind::Periodic, '-31 days');
        $recentPeriodic = $this->media(OnlineMediaKind::Periodic, '-29 days');
        $finishPhoto = $this->media(OnlineMediaKind::Button, '-200 days');
        $oldBurst = $this->media(OnlineMediaKind::Burst, '-366 days');

        $pruned = $this->prune(new PruneOnlineMedia(30));

        self::assertSame(1, $pruned->periodic);
        self::assertSame(1, $pruned->evidence);
        self::assertSame(0, $pruned->failed);
        self::assertFalse($pruned->hasMore());

        self::assertSame(
            [$recentPeriodic->id->toString(), $finishPhoto->id->toString()],
            $this->mediaLeft(),
        );
        self::assertEqualsCanonicalizing([
            'primary/' . $oldPeriodic->objectKey,
            'primary/' . $oldBurst->objectKey,
        ], $this->storage->deleted());

        // Nothing else to do
        $again = $this->prune(new PruneOnlineMedia(30));
        self::assertSame(0, $again->periodic + $again->evidence);
    }

    /**
     * A round's reference image (OnlineReferenceImages) is no evidence - the prune works on evidence rows only, so it
     * never deletes one, however old (the orphan sweep keeps it while a round holds it: SweepOnlineMediaOrphansHandlerTest)
     */
    public function testAReferenceImageIsNeverPruned(): void
    {
        $reference = OnlineReferenceImages::objectKey(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, OnlineRoundsFixture::ROUND_ONLINE_SOLO, Uuid::uuid7()->toString());
        $this->connection->executeStatement(
            'UPDATE online_round SET reference_image = :key WHERE id = :id',
            ['key' => $reference, 'id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO],
        );
        $this->storage->put(OnlineMediaStore::Primary, $reference, 2_000_000, modifiedAt: new DateTimeImmutable('-800 days'));
        $oldPhoto = $this->media(OnlineMediaKind::Button, '-400 days');

        $pruned = $this->prune(new PruneOnlineMedia(1));

        self::assertSame(1, $pruned->evidence);
        self::assertSame(['primary/' . $oldPhoto->objectKey], $this->storage->deleted());
        self::assertSame($reference, $this->connection->fetchOne('SELECT reference_image FROM online_round WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]));
    }

    /**
     * A page at a time, each starting after the last row of the one before
     */
    public function testABigBacklogGoesPageByPage(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO online_entry_media (id, online_entry_id, kind, taken_ms, store, object_key, content_type, declared_bytes, created_at)
             SELECT gen_random_uuid(), :onlineEntry, 'periodic', 1792000000000, 'primary', 'online/backlog/' || n || '.jpg', 'image/jpeg', 35000, NOW() - INTERVAL '40 days'
             FROM generate_series(1, :count) AS n",
            ['onlineEntry' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, 'count' => PruneOnlineMediaHandler::PAGE_SIZE + 1],
        );

        $first = $this->prune(new PruneOnlineMedia(30));
        self::assertSame(PruneOnlineMediaHandler::PAGE_SIZE, $first->periodic);
        self::assertTrue($first->hasMore());

        $second = $this->prune(new PruneOnlineMedia(30, $first->nextAfterCreatedAt, $first->nextAfterId));
        self::assertSame(1, $second->periodic);
        self::assertFalse($second->hasMore());

        self::assertSame(0, $this->connection->fetchOne("SELECT COUNT(*) FROM online_entry_media WHERE object_key LIKE 'online/backlog/%'"));
        self::assertCount(PruneOnlineMediaHandler::PAGE_SIZE + 1, $this->storage->deleted());
    }

    /**
     * One object the store will not delete keeps its row, with a warning - the others go, and the next page starts
     * after it all the same
     */
    public function testAnObjectTheStoreWillNotDeleteStaysForTheNextRun(): void
    {
        $stuck = $this->media(OnlineMediaKind::Periodic, '-40 days');
        $this->media(OnlineMediaKind::Periodic, '-39 days');
        $logger = new InMemoryLogger();

        $pruned = $this->handler(self::failingFor([$stuck->objectKey]), $logger)(new PruneOnlineMedia(30));

        self::assertSame(1, $pruned->periodic);
        self::assertSame(1, $pruned->failed);
        self::assertFalse($pruned->storeFailing);
        self::assertSame([$stuck->id->toString()], $this->mediaLeft());
        self::assertTrue($logger->hasRecord('warning', 'its row stays for the next run'));
    }

    /**
     * A store failing again and again is down: the page stops, so does the run - nothing is deleted from the rows
     */
    public function testAStoreThatKeepsFailingStopsThePage(): void
    {
        $keys = [];

        foreach (range(1, PruneOnlineMediaHandler::MAX_FAILURES_IN_A_ROW + 2) as $day) {
            $keys[] = $this->media(OnlineMediaKind::Periodic, sprintf('-%d days', 40 + $day))->objectKey;
        }

        $pruned = $this->handler(self::failingFor($keys), new InMemoryLogger())(new PruneOnlineMedia(30));

        self::assertTrue($pruned->storeFailing);
        self::assertFalse($pruned->hasMore());
        self::assertSame(PruneOnlineMediaHandler::MAX_FAILURES_IN_A_ROW, $pruned->failed);
        self::assertCount(count($keys), $this->mediaLeft());
    }

    /**
     * No second store is configured here: its rows are left alone, with a warning - never a crash of the prune
     */
    public function testARowOfASecondStoreThatIsNotConfiguredIsSkipped(): void
    {
        $secondary = $this->media(OnlineMediaKind::Burst, '-400 days', OnlineMediaStore::Secondary);
        $primary = $this->media(OnlineMediaKind::Burst, '-399 days');
        $logger = new InMemoryLogger();

        $pruned = $this->handler($this->storage, $logger)(new PruneOnlineMedia(30));

        self::assertSame(1, $pruned->skipped);
        self::assertSame(1, $pruned->evidence);
        self::assertSame([$secondary->id->toString()], $this->mediaLeft());
        self::assertSame(['primary/' . $primary->objectKey], $this->storage->deleted());
        self::assertTrue($logger->hasRecord('warning', 'second store while none is configured'));
    }

    private function prune(PruneOnlineMedia $message): PrunedOnlineMedia
    {
        return self::getContainer()->get(PruneOnlineMediaHandler::class)($message);
    }

    private function handler(OnlineMediaStorage $storage, InMemoryLogger $logger): PruneOnlineMediaHandler
    {
        $container = self::getContainer();

        return new PruneOnlineMediaHandler(
            $this->entityManager,
            $storage,
            $container->get(OnlineMediaStores::class),
            $container->get(ClockInterface::class),
            $logger,
        );
    }

    /**
     * The fake store, refusing to delete these keys
     *
     * @param list<string> $keys
     */
    private static function failingFor(array $keys): OnlineMediaStorage
    {
        return new class ($keys) implements OnlineMediaStorage {
            /**
             * @param list<string> $keys
             */
            public function __construct(
                private readonly array $keys,
            ) {
            }

            public function presignPut(OnlineEntryMedia $media): PresignedUpload
            {
                throw new RuntimeException('Not used');
            }

            public function presignGet(OnlineEntryMedia $media, int $ttlSeconds): string
            {
                throw new RuntimeException('Not used');
            }

            public function head(OnlineEntryMedia $media): null|int
            {
                return null;
            }

            public function inspect(OnlineEntryMedia $media): null|OnlineMediaObject
            {
                return null;
            }

            public function delete(OnlineEntryMedia $media): void
            {
                if (in_array($media->objectKey, $this->keys, true)) {
                    throw new RuntimeException('The store said 503');
                }
            }

            public function listObjects(OnlineMediaStore $store, string $prefix, null|string $startAfter, int $limit): OnlineMediaObjectList
            {
                return new OnlineMediaObjectList([], null);
            }

            public function deleteObjects(OnlineMediaStore $store, array $keys): array
            {
                return [];
            }

            public function probe(OnlineMediaStore $store): bool
            {
                return true;
            }
        };
    }

    /**
     * @return list<string> the ids of the test's media still there, newest first (the fixture's own photo left out)
     */
    private function mediaLeft(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM online_entry_media WHERE id <> :fixture ORDER BY created_at DESC', ['fixture' => OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED]);

        return $ids;
    }

    private function media(OnlineMediaKind $kind, string $age, OnlineMediaStore $store = OnlineMediaStore::Primary): OnlineEntryMedia
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
