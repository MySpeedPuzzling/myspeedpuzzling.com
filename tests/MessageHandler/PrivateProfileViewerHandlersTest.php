<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\PrivateProfileViewersLimitReached;
use SpeedPuzzling\Web\Message\AllowPrivateProfileViewer;
use SpeedPuzzling\Web\Message\BlockUser;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\RevokePrivateProfileViewer;
use SpeedPuzzling\Web\MessageHandler\AllowPrivateProfileViewerHandler;
use SpeedPuzzling\Web\Query\GetPrivateProfileViewers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * PrivateProfileViewerFixture: PLAYER_PRIVATE allows PLAYER_WITH_FAVORITES.
 */
final class PrivateProfileViewerHandlersTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetPrivateProfileViewers $getPrivateProfileViewers;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->getPrivateProfileViewers = self::getContainer()->get(GetPrivateProfileViewers::class);
    }

    public function testAllowingAddsTheViewerOnce(): void
    {
        $this->messageBus->dispatch(new AllowPrivateProfileViewer(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_STRIPE));
        $this->messageBus->dispatch(new AllowPrivateProfileViewer(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_STRIPE));

        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE],
            $this->allowedBy(PlayerFixture::PLAYER_PRIVATE),
        );
    }

    public function testNobodyAllowsThemselves(): void
    {
        $this->messageBus->dispatch(new AllowPrivateProfileViewer(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_PRIVATE));

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $this->allowedBy(PlayerFixture::PLAYER_PRIVATE));
    }

    public function testAllowingNeverTouchesAnotherOwnersList(): void
    {
        $this->messageBus->dispatch(new AllowPrivateProfileViewer(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN));

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $this->allowedBy(PlayerFixture::PLAYER_PRIVATE));
        self::assertSame([PlayerFixture::PLAYER_ADMIN], $this->allowedBy(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testRevokingRemovesTheViewerAndIsIdempotent(): void
    {
        $this->messageBus->dispatch(new RevokePrivateProfileViewer(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES));
        $this->messageBus->dispatch(new RevokePrivateProfileViewer(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES));

        self::assertSame([], $this->allowedBy(PlayerFixture::PLAYER_PRIVATE));
    }

    public function testTheListIsCapped(): void
    {
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('DELETE FROM private_profile_viewer');
        $database->executeStatement(
            <<<SQL
INSERT INTO player (id, code, registered_at)
SELECT gen_random_uuid(), 'cap' || n, NOW() FROM generate_series(1, :count) AS n
SQL,
            ['count' => AllowPrivateProfileViewerHandler::MAX_VIEWERS],
        );
        $database->executeStatement(
            "INSERT INTO private_profile_viewer (id, owner_id, viewer_id, added_at) SELECT gen_random_uuid(), :owner, id, NOW() FROM player WHERE code LIKE 'cap%'",
            ['owner' => PlayerFixture::PLAYER_PRIVATE],
        );

        try {
            $this->messageBus->dispatch(new AllowPrivateProfileViewer(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN));
            self::fail('The cap was not enforced.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(PrivateProfileViewersLimitReached::class, $exception->getPrevious());
        }
    }

    public function testBlockingSomebodyTakesThemOffMyList(): void
    {
        $this->messageBus->dispatch(new BlockUser(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES));

        self::assertSame([], $this->allowedBy(PlayerFixture::PLAYER_PRIVATE));
    }

    public function testDeletingAPlayerRemovesTheirRowsInBothDirections(): void
    {
        $this->messageBus->dispatch(new AllowPrivateProfileViewer(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_FAVORITES));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_WITH_FAVORITES));

        $remaining = self::getContainer()->get(Connection::class)
            ->executeQuery('SELECT COUNT(*) FROM private_profile_viewer WHERE viewer_id = :id OR owner_id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES])
            ->fetchOne();

        self::assertSame(0, $remaining);
    }

    /**
     * @return list<string>
     */
    private function allowedBy(string $ownerId): array
    {
        return array_map(
            static fn (PlayerIdentification $viewer): string => $viewer->playerId,
            $this->getPrivateProfileViewers->ofOwner($ownerId),
        );
    }
}
