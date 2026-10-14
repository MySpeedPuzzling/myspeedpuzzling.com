<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Message\SweepOnlineMediaOrphans;
use SpeedPuzzling\Web\MessageHandler\SweepOnlineMediaOrphansHandler;
use SpeedPuzzling\Web\Query\GetOnlineMediaObjectKeys;
use SpeedPuzzling\Web\Results\SweptOnlineMediaOrphans;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReferenceImages;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Value\OnlineMediaObject;
use SpeedPuzzling\Web\Value\OnlineMediaObjectList;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\PresignedUpload;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Evidence objects whose row is gone - a deleted competition, round or participant takes the rows (cascade), never the
 * objects - are swept: older than a day, a page at a time, going on where the last page stopped.
 */
final class SweepOnlineMediaOrphansHandlerTest extends KernelTestCase
{
    private FakeOnlineMediaStorage $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->storage = self::getContainer()->get(FakeOnlineMediaStorage::class);
        // The marker lives in the shared cache - on disk in tests, across tests and runs
        self::getContainer()->get(CacheItemPoolInterface::class)->deleteItem(SweepOnlineMediaOrphansHandler::MARKER_KEY_PREFIX . 'primary');
    }

    public function testOldObjectsWithoutARowAreDeleted(): void
    {
        $old = new DateTimeImmutable('-2 days');
        $orphan = self::key();
        $youngOrphan = self::key();
        $fixtureKey = sprintf('online/%s/%s/%s/%s.jpg', OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, OnlineRoundsFixture::ROUND_ONLINE_SOLO, OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED);

        $this->storage->put(OnlineMediaStore::Primary, $orphan, 35_000, modifiedAt: $old);
        $this->storage->put(OnlineMediaStore::Primary, $youngOrphan, 35_000, modifiedAt: new DateTimeImmutable('-2 hours'));
        // The fixture's empty mat photo has its row
        $this->storage->put(OnlineMediaStore::Primary, $fixtureKey, 182_000, modifiedAt: $old);
        // Not evidence at all
        $this->storage->put(OnlineMediaStore::Primary, 'original/some-puzzle.jpg', 90_000, modifiedAt: $old);

        $swept = $this->sweep(OnlineMediaStore::Primary);

        self::assertSame(3, $swept->listed);
        self::assertSame(1, $swept->orphans);
        self::assertSame(1, $swept->deleted);
        self::assertFalse($swept->hasMore);
        self::assertSame(['primary/' . $orphan], $this->storage->deleted());
    }

    /**
     * A round's reference image (OnlineReferenceImages) is no evidence: the sweep keeps it as long as an online round
     * holds it, however old - and takes it once none does (the round or its event deleted, an upload rolled back)
     */
    public function testAReferenceImageIsKeptWhileARoundHoldsIt(): void
    {
        $old = new DateTimeImmutable('-400 days');
        $held = OnlineReferenceImages::objectKey(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, OnlineRoundsFixture::ROUND_ONLINE_SOLO, Uuid::uuid7()->toString());
        $replacedLongAgo = OnlineReferenceImages::objectKey(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, OnlineRoundsFixture::ROUND_ONLINE_SOLO, Uuid::uuid7()->toString());
        $ofADeletedRound = OnlineReferenceImages::objectKey(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString());
        $justUploaded = OnlineReferenceImages::objectKey(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString());
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE online_round SET reference_image = :key WHERE id = :id',
            ['key' => $held, 'id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO],
        );

        $this->storage->put(OnlineMediaStore::Primary, $held, 2_000_000, modifiedAt: $old);
        $this->storage->put(OnlineMediaStore::Primary, $replacedLongAgo, 2_000_000, modifiedAt: $old);
        $this->storage->put(OnlineMediaStore::Primary, $ofADeletedRound, 2_000_000, modifiedAt: $old);
        // Its save may still be committing - a day keeps far away from it
        $this->storage->put(OnlineMediaStore::Primary, $justUploaded, 2_000_000, modifiedAt: new DateTimeImmutable('-2 hours'));

        $swept = $this->sweep(OnlineMediaStore::Primary);

        self::assertSame(4, $swept->listed);
        self::assertSame(2, $swept->deleted);
        self::assertEqualsCanonicalizing(['primary/' . $replacedLongAgo, 'primary/' . $ofADeletedRound], $this->storage->deleted());
    }

    public function testABigStoreIsSweptPageByPage(): void
    {
        $old = new DateTimeImmutable('-3 days');

        foreach (range(0, SweepOnlineMediaOrphansHandler::PAGE_SIZE) as $ignored) {
            $this->storage->put(OnlineMediaStore::Primary, self::key(), 1_000, modifiedAt: $old);
        }

        $first = $this->sweep(OnlineMediaStore::Primary);
        self::assertSame(SweepOnlineMediaOrphansHandler::PAGE_SIZE, $first->deleted);
        self::assertTrue($first->hasMore);

        // The next page goes on where the first one stopped - in this run, or the next one
        $second = $this->sweep(OnlineMediaStore::Primary);
        self::assertSame(1, $second->listed);
        self::assertSame(1, $second->deleted);
        self::assertFalse($second->hasMore);

        self::assertCount(SweepOnlineMediaOrphansHandler::PAGE_SIZE + 1, $this->storage->deleted());
    }

    public function testAStoreThatDoesNotAnswerIsSweptAnotherDay(): void
    {
        $logger = new InMemoryLogger();
        $container = self::getContainer();
        $handler = new SweepOnlineMediaOrphansHandler(
            self::unreachableStorage(),
            $container->get(OnlineMediaStores::class),
            $container->get(GetOnlineMediaObjectKeys::class),
            $container->get(CacheItemPoolInterface::class),
            $container->get(ClockInterface::class),
            $logger,
        );

        $swept = $handler(new SweepOnlineMediaOrphans(OnlineMediaStore::Primary));

        self::assertSame(0, $swept->listed);
        self::assertFalse($swept->hasMore);
        self::assertTrue($logger->hasRecord('warning', 'orphan sweep failed'));
    }

    public function testASecondStoreThatIsNotConfiguredIsNotSwept(): void
    {
        $this->storage->put(OnlineMediaStore::Secondary, self::key(), 1_000, modifiedAt: new DateTimeImmutable('-3 days'));

        $swept = $this->sweep(OnlineMediaStore::Secondary);

        self::assertSame(0, $swept->listed);
        self::assertSame([], $this->storage->deleted());
    }

    private function sweep(OnlineMediaStore $store): SweptOnlineMediaOrphans
    {
        return self::getContainer()->get(SweepOnlineMediaOrphansHandler::class)(new SweepOnlineMediaOrphans($store));
    }

    private static function key(): string
    {
        return sprintf('online/%s/%s/%s/%s.jpg', Uuid::uuid7(), Uuid::uuid7(), Uuid::uuid7(), Uuid::uuid7());
    }

    private static function unreachableStorage(): OnlineMediaStorage
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
                return null;
            }

            public function inspect(OnlineEntryMedia $media): null|OnlineMediaObject
            {
                return null;
            }

            public function delete(OnlineEntryMedia $media): void
            {
            }

            public function listObjects(OnlineMediaStore $store, string $prefix, null|string $startAfter, int $limit): OnlineMediaObjectList
            {
                throw new RuntimeException('Connection timed out');
            }

            public function deleteObjects(OnlineMediaStore $store, array $keys): array
            {
                return $keys;
            }

            public function probe(OnlineMediaStore $store): bool
            {
                return true;
            }
        };
    }
}
