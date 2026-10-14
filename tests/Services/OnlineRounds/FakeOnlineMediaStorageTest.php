<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The test environment's object storage for the evidence (config/services_test.php) - what the phone's upload, the
 * server's check and the pruning see.
 */
final class FakeOnlineMediaStorageTest extends KernelTestCase
{
    public function testTheTestEnvironmentUsesTheFake(): void
    {
        // @phpstan-ignore staticMethod.impossibleType (PHPStan reads the dev container, which binds S3OnlineMediaStorage)
        self::assertSame(self::getContainer()->get(FakeOnlineMediaStorage::class), self::getContainer()->get(OnlineMediaStorage::class));
    }

    public function testAStoreAnswersItsProbeUntilTakenDown(): void
    {
        $storage = new FakeOnlineMediaStorage(new MockClock());

        self::assertTrue($storage->probe(OnlineMediaStore::Primary));
        $storage->takeDown(OnlineMediaStore::Primary);
        self::assertFalse($storage->probe(OnlineMediaStore::Primary));
        self::assertTrue($storage->probe(OnlineMediaStore::Secondary));
        $storage->takeDown(OnlineMediaStore::Primary, false);
        self::assertTrue($storage->probe(OnlineMediaStore::Primary));

        self::assertSame(['primary', 'primary', 'secondary', 'primary'], $storage->probes());
    }

    public function testAPresignedUploadIsSignedForTheMediasStoreTypeAndLength(): void
    {
        $storage = new FakeOnlineMediaStorage(new MockClock(new DateTimeImmutable('2026-10-13 12:00:00', new DateTimeZone('UTC'))));
        $media = $this->media();

        $upload = $storage->presignPut($media);

        self::assertStringStartsWith(FakeOnlineMediaStorage::BASE_URL . '/primary/' . $media->objectKey, $upload->url);
        self::assertSame(['Content-Type' => 'image/jpeg', 'Content-Length' => '182000'], $upload->headers);
        self::assertSame(1_791_892_800_000 + FakeOnlineMediaStorage::PUT_LIFETIME_SECONDS * 1000, $upload->expiresAtMs);
        self::assertSame(OnlineMediaStore::Primary, $upload->store);

        $media->store = OnlineMediaStore::Secondary;
        self::assertSame(OnlineMediaStore::Secondary, $storage->presignPut($media)->store);
        self::assertStringContainsString('/secondary/', $storage->presignGet($media, 60));
    }

    public function testAnObjectIsThereOnlyAfterItsUploadUntilItIsDeleted(): void
    {
        $storage = new FakeOnlineMediaStorage(new MockClock());
        $media = $this->media();

        self::assertNull($storage->head($media));

        $storage->upload($media);
        self::assertSame(182_000, $storage->head($media));
        $storage->upload($media, 1_000);
        self::assertSame(1_000, $storage->head($media));

        $storage->delete($media);
        self::assertNull($storage->head($media));
        self::assertSame(['primary/' . $media->objectKey], $storage->deleted());
    }

    private function media(): OnlineEntryMedia
    {
        $media = self::getContainer()->get(EntityManagerInterface::class)->find(OnlineEntryMedia::class, OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED);
        self::assertNotNull($media);

        return $media;
    }
}
