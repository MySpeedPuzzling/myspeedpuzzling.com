<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Storage;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Message\DeleteOrphanedAvatar;
use SpeedPuzzling\Web\Services\Storage\OrphanedAvatarFinder;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrphanedAvatarFinderTest extends KernelTestCase
{
    private const string REFERENCED = 'avatars/referenced.jpg';
    private const string ORPHAN = 'avatars/orphan.jpg';
    private const string FRESH_ORPHAN = 'avatars/fresh-upload.jpg';
    private const string NOT_AN_AVATAR = 'players/someone/photo.jpg';

    private Filesystem $filesystem;
    private OrphanedAvatarFinder $finder;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->filesystem = $container->get(Filesystem::class);
        $this->finder = $container->get(OrphanedAvatarFinder::class);

        $container->get(Connection::class)->executeStatement('UPDATE player SET avatar = :a WHERE id = :id', [
            'a' => self::REFERENCED,
            'id' => PlayerFixture::PLAYER_REGULAR,
        ]);

        $old = time() - 3 * 86400;
        $this->filesystem->write(self::REFERENCED, 'image', ['timestamp' => $old]);
        $this->filesystem->write(self::ORPHAN, 'image', ['timestamp' => $old]);
        // Uploaded a moment ago - its transaction may not have committed yet
        $this->filesystem->write(self::FRESH_ORPHAN, 'image');
        $this->filesystem->write(self::NOT_AN_AVATAR, 'image', ['timestamp' => $old]);
    }

    public function testFindsOnlyOldUnreferencedAvatars(): void
    {
        self::assertSame([self::ORPHAN], $this->finder->find());
    }

    public function testIsOrphan(): void
    {
        self::assertTrue($this->finder->isOrphan(self::ORPHAN));
        self::assertFalse($this->finder->isOrphan(self::REFERENCED));
        self::assertFalse($this->finder->isOrphan(self::NOT_AN_AVATAR), 'Only the avatar prefix is ever touched');
    }

    public function testDeleteHandlerRemovesOrphanAndKeepsReferenced(): void
    {
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $messageBus->dispatch(new DeleteOrphanedAvatar(self::ORPHAN));
        $messageBus->dispatch(new DeleteOrphanedAvatar(self::REFERENCED));
        $messageBus->dispatch(new DeleteOrphanedAvatar(self::NOT_AN_AVATAR));

        self::assertFalse($this->filesystem->fileExists(self::ORPHAN));
        self::assertTrue($this->filesystem->fileExists(self::REFERENCED));
        self::assertTrue($this->filesystem->fileExists(self::NOT_AN_AVATAR));
    }
}
