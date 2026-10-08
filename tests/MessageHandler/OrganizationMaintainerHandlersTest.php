<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\AddOrganizationMaintainer;
use SpeedPuzzling\Web\Message\RemoveOrganizationMaintainer;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrganizationMaintainerHandlersTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAddingIsIdempotent(): void
    {
        $this->messageBus->dispatch(new AddOrganizationMaintainer(OrganizationFixture::ORGANIZATION_RIVERBEND, PlayerFixture::PLAYER_REGULAR));
        $this->messageBus->dispatch(new AddOrganizationMaintainer(OrganizationFixture::ORGANIZATION_RIVERBEND, PlayerFixture::PLAYER_REGULAR));

        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES], $this->maintainers(OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testTheCreatorIsNeverAddedAsAMaintainer(): void
    {
        $this->messageBus->dispatch(new AddOrganizationMaintainer(OrganizationFixture::ORGANIZATION_RIVERBEND, PlayerFixture::PLAYER_WITH_STRIPE));

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $this->maintainers(OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testRemovingIsIdempotent(): void
    {
        $this->messageBus->dispatch(new RemoveOrganizationMaintainer(OrganizationFixture::ORGANIZATION_RIVERBEND, PlayerFixture::PLAYER_WITH_FAVORITES));
        $this->messageBus->dispatch(new RemoveOrganizationMaintainer(OrganizationFixture::ORGANIZATION_RIVERBEND, PlayerFixture::PLAYER_WITH_FAVORITES));
        // Somebody who never was a maintainer, and the creator, change nothing
        $this->messageBus->dispatch(new RemoveOrganizationMaintainer(OrganizationFixture::ORGANIZATION_RIVERBEND, PlayerFixture::PLAYER_REGULAR));
        $this->messageBus->dispatch(new RemoveOrganizationMaintainer(OrganizationFixture::ORGANIZATION_RIVERBEND, PlayerFixture::PLAYER_WITH_STRIPE));

        self::assertSame([], $this->maintainers(OrganizationFixture::ORGANIZATION_RIVERBEND));
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $this->connection->fetchOne(
            'SELECT added_by_player_id FROM organization WHERE id = :id',
            ['id' => OrganizationFixture::ORGANIZATION_RIVERBEND],
        ));
    }

    /**
     * @return list<string>
     */
    private function maintainers(string $organizationId): array
    {
        /** @var list<string> $playerIds */
        $playerIds = $this->connection->fetchFirstColumn(
            'SELECT player_id FROM organization_maintainer WHERE organization_id = :id ORDER BY player_id',
            ['id' => $organizationId],
        );

        return $playerIds;
    }
}
