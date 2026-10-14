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
use SpeedPuzzling\Web\Message\MarkAbandonedOnlineMedia;
use SpeedPuzzling\Web\MessageHandler\MarkAbandonedOnlineMediaHandler;
use SpeedPuzzling\Web\Results\MarkedAbandonedOnlineMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaObject;
use SpeedPuzzling\Web\Value\OnlineMediaObjectList;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\PresignedUpload;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Uploads that never arrived (README §5.11): a row the phone never reported uploaded, long after its URL ran out, with
 * nothing in its store is marked abandoned - the review says "never arrived"; an object that is there (its report may
 * still come), a row still within its time and an uploaded one stay as they are. Through entity methods, a page at a
 * time, never stuck on a store that does not answer.
 */
final class MarkAbandonedOnlineMediaHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FakeOnlineMediaStorage $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->storage = $container->get(FakeOnlineMediaStorage::class);
    }

    public function testAnUploadThatNeverArrivedIsMarkedAbandoned(): void
    {
        $neverArrived = $this->media('-2 hours');
        $inTheStore = $this->media('-2 hours');
        $this->storage->upload($inTheStore);
        $stillInTime = $this->media(sprintf('-%d seconds', MarkAbandonedOnlineMediaHandler::ABANDONED_AFTER_SECONDS - 60));
        $uploaded = $this->media('-3 hours');
        $uploaded->markUploaded(1_792_002_840_000);
        $this->entityManager->flush();

        $marked = $this->dispatch(new MarkAbandonedOnlineMedia());
        $this->entityManager->flush();

        self::assertSame(1, $marked->abandoned);
        self::assertSame(1, $marked->inStore);
        self::assertSame(0, $marked->failed);
        self::assertFalse($marked->hasMore());

        self::assertSame([$neverArrived->id->toString()], $this->abandonedIds());
        self::assertNull($inTheStore->abandonedAt, 'its report may still come');
        self::assertNull($stillInTime->abandonedAt);
        self::assertNull($uploaded->abandonedAt);

        // Marked once - the next run leaves it, and the moment stays
        $abandonedAt = $neverArrived->abandonedAt;
        self::assertNotNull($abandonedAt);
        $again = $this->dispatch(new MarkAbandonedOnlineMedia());
        self::assertSame(0, $again->abandoned);
        self::assertEquals($abandonedAt, $neverArrived->abandonedAt);
    }

    /**
     * A phone that was offline for long reports the upload after all - the upload wins
     */
    public function testALateReportWinsOverTheMark(): void
    {
        $media = $this->media('-2 hours');
        $this->dispatch(new MarkAbandonedOnlineMedia());
        self::assertNotNull($media->abandonedAt);

        $media->markUploaded(1_792_002_840_000);

        self::assertNull($media->abandonedAt);
        self::assertFalse($media->markAbandoned(new DateTimeImmutable()), 'an uploaded one is never marked');
    }

    public function testABacklogGoesPageByPage(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO online_entry_media (id, online_entry_id, kind, taken_ms, store, object_key, content_type, declared_bytes, created_at)
             SELECT gen_random_uuid(), :onlineEntry, 'periodic', 1792000000000, 'primary', 'online/never/' || n || '.jpg', 'image/jpeg', 35000, NOW() - INTERVAL '3 hours'
             FROM generate_series(1, :count) AS n",
            ['onlineEntry' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, 'count' => MarkAbandonedOnlineMediaHandler::PAGE_SIZE + 1],
        );

        $first = $this->dispatch(new MarkAbandonedOnlineMedia());
        self::assertSame(MarkAbandonedOnlineMediaHandler::PAGE_SIZE, $first->abandoned);
        self::assertTrue($first->hasMore());

        $second = $this->dispatch(new MarkAbandonedOnlineMedia($first->nextAfterCreatedAt, $first->nextAfterId));
        self::assertSame(1, $second->abandoned);
        self::assertFalse($second->hasMore());

        self::assertSame(0, $this->connection()->fetchOne("SELECT COUNT(*) FROM online_entry_media WHERE object_key LIKE 'online/never/%' AND abandoned_at IS NULL"));
    }

    /**
     * A store that does not answer marks nothing - three failures in a row stop the page, the next run asks again
     */
    public function testAStoreThatDoesNotAnswerMarksNothing(): void
    {
        foreach (range(1, MarkAbandonedOnlineMediaHandler::MAX_FAILURES_IN_A_ROW + 2) as $minutes) {
            $this->media(sprintf('-%d minutes', 60 + $minutes));
        }

        $logger = new InMemoryLogger();
        $marked = $this->handler(self::failingStore(), $logger)(new MarkAbandonedOnlineMedia());

        self::assertTrue($marked->storeFailing);
        self::assertFalse($marked->hasMore());
        self::assertSame(MarkAbandonedOnlineMediaHandler::MAX_FAILURES_IN_A_ROW, $marked->failed);
        self::assertSame(0, $marked->abandoned);
        self::assertSame([], $this->abandonedIds());
        self::assertTrue($logger->hasRecord('warning', 'the next run asks again'));
    }

    /**
     * No second store is configured here: its rows are skipped - never marked without asking the store
     */
    public function testARowOfASecondStoreThatIsNotConfiguredIsSkipped(): void
    {
        $this->media('-2 hours', OnlineMediaStore::Secondary);

        $marked = $this->dispatch(new MarkAbandonedOnlineMedia());

        self::assertSame(1, $marked->skipped);
        self::assertSame(0, $marked->abandoned);
        self::assertSame([], $this->abandonedIds());
    }

    private function dispatch(MarkAbandonedOnlineMedia $message): MarkedAbandonedOnlineMedia
    {
        $result = self::getContainer()->get(MessageBusInterface::class)->dispatch($message)->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(MarkedAbandonedOnlineMedia::class, $result);

        return $result;
    }

    private function handler(OnlineMediaStorage $storage, InMemoryLogger $logger): MarkAbandonedOnlineMediaHandler
    {
        $container = self::getContainer();

        return new MarkAbandonedOnlineMediaHandler(
            $this->entityManager,
            $storage,
            $container->get(OnlineMediaStores::class),
            $container->get(ClockInterface::class),
            $logger,
        );
    }

    private static function failingStore(): OnlineMediaStorage
    {
        return new class () implements OnlineMediaStorage {
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
                throw new RuntimeException('The store did not answer');
            }

            public function inspect(OnlineEntryMedia $media): null|OnlineMediaObject
            {
                throw new RuntimeException('The store did not answer');
            }

            public function delete(OnlineEntryMedia $media): void
            {
                throw new RuntimeException('Not used');
            }

            public function listObjects(OnlineMediaStore $store, string $prefix, null|string $startAfter, int $limit): OnlineMediaObjectList
            {
                return new OnlineMediaObjectList([], null);
            }

            public function deleteObjects(OnlineMediaStore $store, array $keys): array
            {
                return [];
            }
        };
    }

    /**
     * @return list<string>
     */
    private function abandonedIds(): array
    {
        $this->entityManager->flush();

        /** @var list<string> $ids */
        $ids = $this->connection()->fetchFirstColumn('SELECT id FROM online_entry_media WHERE abandoned_at IS NOT NULL ORDER BY created_at');

        return $ids;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function media(string $age, OnlineMediaStore $store = OnlineMediaStore::Primary): OnlineEntryMedia
    {
        $onlineEntry = $this->entityManager->find(OnlineEntry::class, OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        self::assertNotNull($onlineEntry);

        $id = Uuid::uuid7();
        $media = new OnlineEntryMedia(
            id: $id,
            onlineEntry: $onlineEntry,
            kind: OnlineMediaKind::Periodic,
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
