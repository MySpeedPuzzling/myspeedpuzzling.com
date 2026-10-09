<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\UnfollowCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class UnfollowCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testRemovesTheFollow(): void
    {
        $this->messageBus->dispatch(new UnfollowCompetition(PlayerFixture::PLAYER_REGULAR, 'series:' . EventsPageFixture::SERIES_HARBOR_NIGHTS));

        self::assertSame(0, $this->countFollows(PlayerFixture::PLAYER_REGULAR, 'series_id', EventsPageFixture::SERIES_HARBOR_NIGHTS));
        // The other follow of the player stays
        self::assertSame(1, $this->countFollows(PlayerFixture::PLAYER_REGULAR, 'competition_id', EventsPageFixture::COMPETITION_MEADOW_TBA));
    }

    public function testUnknownOrNotFollowedIsANoOp(): void
    {
        $this->messageBus->dispatch(new UnfollowCompetition(PlayerFixture::PLAYER_REGULAR, 'competition:' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));
        $this->messageBus->dispatch(new UnfollowCompetition(PlayerFixture::PLAYER_REGULAR, 'competition:018d0040-0000-0000-0000-0000000000ff'));
        $this->messageBus->dispatch(new UnfollowCompetition(PlayerFixture::PLAYER_REGULAR, 'garbage'));

        // EventsPageFixture's two follows + OrganizationFixture's three (an organization and two series)
        self::assertSame(5, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM followed_competition WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_REGULAR],
        ));
    }

    public function testWorksForATargetThatIsNoLongerVisible(): void
    {
        $this->connection->executeStatement(
            'UPDATE competition SET approved_at = NULL WHERE id = :id',
            ['id' => EventsPageFixture::COMPETITION_MEADOW_TBA],
        );

        $this->messageBus->dispatch(new UnfollowCompetition(PlayerFixture::PLAYER_REGULAR, 'competition:' . EventsPageFixture::COMPETITION_MEADOW_TBA));

        self::assertSame(0, $this->countFollows(PlayerFixture::PLAYER_REGULAR, 'competition_id', EventsPageFixture::COMPETITION_MEADOW_TBA));
    }

    private function countFollows(string $playerId, string $column, string $targetId): int
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM followed_competition WHERE player_id = :player AND {$column} = :target",
            ['player' => $playerId, 'target' => $targetId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
