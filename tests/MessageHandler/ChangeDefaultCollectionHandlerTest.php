<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Message\ChangeDefaultCollection;
use SpeedPuzzling\Web\Message\DeleteCollection;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/collections/default-collection.md
 */
final class ChangeDefaultCollectionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAnOwnCollectionBecomesTheDefaultAndNullGoesBackToTheSystemCollection(): void
    {
        self::assertNull($this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->messageBus->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->messageBus->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_STRIPE_TREFL));
        self::assertSame(CollectionFixture::COLLECTION_STRIPE_TREFL, $this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->messageBus->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_WITH_STRIPE, null));
        self::assertNull($this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testSomebodyElsesCollectionIsRefused(): void
    {
        try {
            $this->messageBus->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PRIVATE));
            self::fail('Somebody else\'s collection must be refused');
        } catch (CollectionNotFound | HandlerFailedException $exception) {
            self::assertInstanceOf(CollectionNotFound::class, $exception instanceof HandlerFailedException ? $exception->getPrevious() : $exception);
        }

        self::assertNull($this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testDeletingTheDefaultCollectionFallsBackToTheSystemCollection(): void
    {
        $this->messageBus->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_STRIPE_TREFL));

        // Another collection going leaves the default alone
        $this->messageBus->dispatch(new DeleteCollection(collectionId: CollectionFixture::COLLECTION_PUBLIC, playerId: PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame(CollectionFixture::COLLECTION_STRIPE_TREFL, $this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->messageBus->dispatch(new DeleteCollection(collectionId: CollectionFixture::COLLECTION_STRIPE_TREFL, playerId: PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertNull($this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAPlayerWithADefaultCollectionCanBeDeleted(): void
    {
        $this->messageBus->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_REGULAR, CollectionFixture::COLLECTION_PRIVATE));

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM collection WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_REGULAR]));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM player WHERE id = :player', ['player' => PlayerFixture::PLAYER_REGULAR]));
    }

    private function defaultOf(string $playerId): null|string
    {
        $value = $this->database->fetchOne('SELECT default_collection_id FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertNotFalse($value, 'the player exists');

        return is_string($value) ? $value : null;
    }
}
