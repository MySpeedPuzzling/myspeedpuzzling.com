<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineMediaObjectMissing;
use SpeedPuzzling\Web\Message\VerifyOnlineMedia;
use SpeedPuzzling\Web\MessageHandler\VerifyOnlineMediaHandler;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The async check of an upload a phone reported (VerifyOnlineMediaHandler): the object's size from its store, or a
 * failure the transport tries again.
 */
final class VerifyOnlineMediaHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FakeOnlineMediaStorage $storage;
    private VerifyOnlineMediaHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->storage = $container->get(FakeOnlineMediaStorage::class);
        $this->handler = $container->get(VerifyOnlineMediaHandler::class);
    }

    public function testAnUploadedObjectIsVerifiedWithItsRealSize(): void
    {
        $media = $this->media();
        $this->storage->upload($media, 181_990);

        ($this->handler)(new VerifyOnlineMedia($media->id->toString()));
        $this->entityManager->flush();

        self::assertSame(181_990, $this->verifiedBytes($media));
    }

    public function testAMissingObjectFailsForTheTransportToTryAgain(): void
    {
        $media = $this->media();

        try {
            ($this->handler)(new VerifyOnlineMedia($media->id->toString()));
            self::fail('A missing object fails the message');
        } catch (OnlineMediaObjectMissing) {
            // expected
        }

        $this->entityManager->flush();
        self::assertNull($this->verifiedBytes($media));
    }

    public function testAMediaDeletedMeanwhileHasNothingToCheck(): void
    {
        ($this->handler)(new VerifyOnlineMedia(Uuid::uuid7()->toString()));

        $this->expectNotToPerformAssertions();
    }

    private function media(): OnlineEntryMedia
    {
        $onlineEntry = $this->entityManager->find(OnlineEntry::class, OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        self::assertNotNull($onlineEntry);

        $id = Uuid::uuid7();
        $media = new OnlineEntryMedia(
            id: $id,
            onlineEntry: $onlineEntry,
            kind: OnlineMediaKind::Button,
            takenMs: 1_792_002_832_345,
            store: OnlineMediaStore::Primary,
            objectKey: 'online/test/' . $id->toString() . '.jpg',
            contentType: 'image/jpeg',
            declaredBytes: 182_000,
            createdAt: new DateTimeImmutable(),
        );
        $media->markUploaded(1_792_002_833_000);
        $this->entityManager->persist($media);
        $this->entityManager->flush();

        return $media;
    }

    private function verifiedBytes(OnlineEntryMedia $media): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne('SELECT verified_bytes FROM online_entry_media WHERE id = :id', ['id' => $media->id->toString()]);
    }
}
