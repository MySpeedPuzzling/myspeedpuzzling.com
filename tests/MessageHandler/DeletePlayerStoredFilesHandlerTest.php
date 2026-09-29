<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\DeletePlayerStoredFiles;
use SpeedPuzzling\Web\MessageHandler\DeletePlayerStoredFilesHandler;
use SpeedPuzzling\Web\Query\GetStoredFileReferences;
use SpeedPuzzling\Web\Services\Storage\UploadSpool;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestDouble\DeletePlayerThenFail;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class DeletePlayerStoredFilesHandlerTest extends KernelTestCase
{
    private const string AVATAR = 'avatars/018d0000-0000-0000-0000-000000000001-1700000000.jpg';
    // TIME_06: solo time of PLAYER_REGULAR - removed with the account
    private const string SOLO_PHOTO = 'players/018d0000-0000-0000-0000-000000000001/solo-1700000000.jpg';
    // TIME_12: pair time owned by PLAYER_REGULAR - handed over to PLAYER_PRIVATE
    private const string PAIR_PHOTO = 'players/018d0000-0000-0000-0000-000000000001/pair-1700000000.jpg';
    private const string RESULT_IMAGE = 'players/018d0000-0000-0000-0000-000000000001/results/some-time.png';
    private const string STALE_PHOTO = 'players/018d0000-0000-0000-0000-000000000001/replaced-1600000000.jpg';

    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private Filesystem $filesystem;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->filesystem = $container->get(Filesystem::class);

        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->asyncTransport = $transport;

        $this->connection->executeStatement('UPDATE player SET avatar = :a WHERE id = :id', [
            'a' => self::AVATAR,
            'id' => PlayerFixture::PLAYER_REGULAR,
        ]);
        $this->connection->executeStatement('UPDATE puzzle_solving_time SET finished_puzzle_photo = :p WHERE id = :id', [
            'p' => self::SOLO_PHOTO,
            'id' => PuzzleSolvingTimeFixture::TIME_06,
        ]);
        $this->connection->executeStatement('UPDATE puzzle_solving_time SET finished_puzzle_photo = :p WHERE id = :id', [
            'p' => self::PAIR_PHOTO,
            'id' => PuzzleSolvingTimeFixture::TIME_12,
        ]);
    }

    public function testDeletionRequestsRemovalOfAvatarAndPhotosOfRemovedTimes(): void
    {
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));

        $message = $this->sentMessage();

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $message->playerId);
        self::assertContains(self::AVATAR, $message->paths);
        self::assertContains(self::SOLO_PHOTO, $message->paths);
        // The pair time lives on with the other member, and so does its photo
        self::assertNotContains(self::PAIR_PHOTO, $message->paths);
    }

    public function testRolledBackDeletionRequestsNothing(): void
    {
        try {
            $this->messageBus->dispatch(new DeletePlayerThenFail(PlayerFixture::PLAYER_REGULAR));
            self::fail('The wrapping handler must fail');
        } catch (\Throwable) {
            // expected - the whole deletion is rolled back
        }

        self::assertSame([], $this->sentMessagesOfType());

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR));
    }

    public function testDeletesOwnFilesAndKeepsWhatIsStillReferenced(): void
    {
        foreach ([self::AVATAR, self::SOLO_PHOTO, self::PAIR_PHOTO, self::RESULT_IMAGE, self::STALE_PHOTO] as $path) {
            $this->filesystem->write($path, 'image');
        }
        $otherPlayersPhoto = 'players/' . PlayerFixture::PLAYER_PRIVATE . '/their-photo.jpg';
        $this->filesystem->write($otherPlayersPhoto, 'image');

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->handler()($this->sentMessage());

        self::assertFalse($this->filesystem->fileExists(self::AVATAR));
        self::assertFalse($this->filesystem->fileExists(self::SOLO_PHOTO));
        self::assertFalse($this->filesystem->fileExists(self::RESULT_IMAGE));
        self::assertFalse($this->filesystem->fileExists(self::STALE_PHOTO));
        self::assertTrue($this->filesystem->fileExists(self::PAIR_PHOTO), 'Photo of the handed-over pair time stays');
        self::assertTrue($this->filesystem->fileExists($otherPlayersPhoto));
    }

    public function testMissingObjectsAreFine(): void
    {
        $logger = new InMemoryLogger();
        $handler = new DeletePlayerStoredFilesHandler(
            $this->filesystem,
            new GetStoredFileReferences($this->connection),
            $logger,
        );

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $handler($this->sentMessage());

        self::assertFalse($logger->hasRecord('warning', ''));
    }

    public function testAvatarStillWaitingInUploadSpoolIsDropped(): void
    {
        /** @var ToggleableFailingFilesystemAdapter $s3Adapter */
        $s3Adapter = self::getContainer()->get('app.storage.s3_adapter');
        $spool = self::getContainer()->get(UploadSpool::class);

        // Object storage was down when the avatar was uploaded - it only sits in the spool
        $s3Adapter->setFailing(true);
        $this->filesystem->write(self::AVATAR, 'image');
        $s3Adapter->setFailing(false);
        self::assertTrue($spool->hasPayload(self::AVATAR));

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->handler()($this->sentMessage());

        self::assertFalse($spool->hasPayload(self::AVATAR), 'The drain cron must not upload it later');
        self::assertNull($spool->pendingOperationFor(self::AVATAR));
    }

    public function testStorageFailureIsLoggedAndDoesNotThrow(): void
    {
        $failingAdapter = new ToggleableFailingFilesystemAdapter();
        $filesystem = new Filesystem($failingAdapter);
        $filesystem->write(self::AVATAR, 'image');
        $failingAdapter->setFailing(true);

        $logger = new InMemoryLogger();
        $handler = new DeletePlayerStoredFilesHandler(
            $filesystem,
            new GetStoredFileReferences($this->connection),
            $logger,
        );

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $handler($this->sentMessage());

        self::assertTrue($logger->hasRecord('warning', 'Could not delete a stored file of a deleted player'));
        $record = array_find($logger->records, static fn (array $r): bool => $r['level'] === 'warning');
        self::assertNotNull($record);
        self::assertInstanceOf(\Throwable::class, $record['context']['exception'] ?? null);
    }

    public function testRefusesWhileThePlayerStillExists(): void
    {
        $this->filesystem->write(self::AVATAR, 'image');
        $this->filesystem->write(self::RESULT_IMAGE, 'image');

        $this->handler()(new DeletePlayerStoredFiles(PlayerFixture::PLAYER_REGULAR, [self::AVATAR]));

        self::assertTrue($this->filesystem->fileExists(self::AVATAR));
        self::assertTrue($this->filesystem->fileExists(self::RESULT_IMAGE));
    }

    private function handler(): DeletePlayerStoredFilesHandler
    {
        return self::getContainer()->get(DeletePlayerStoredFilesHandler::class);
    }

    private function sentMessage(): DeletePlayerStoredFiles
    {
        $messages = $this->sentMessagesOfType();
        self::assertCount(1, $messages);

        return $messages[0];
    }

    /**
     * @return list<DeletePlayerStoredFiles>
     */
    private function sentMessagesOfType(): array
    {
        $messages = [];

        foreach ($this->asyncTransport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof DeletePlayerStoredFiles) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
