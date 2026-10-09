<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\OrganizationNotEmpty;
use SpeedPuzzling\Web\Message\DeleteOrganization;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Only an empty organization is deleted (docs/features/organizations/README.md, P4); its follows, team and redirect rows
 * go with it.
 */
final class DeleteOrganizationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAnOrganizationWithSeriesAndEventsIsNotDeleted(): void
    {
        try {
            $this->messageBus->dispatch(new DeleteOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND));
            self::fail('OrganizationNotEmpty expected');
        } catch (OrganizationNotEmpty $exception) {
            // Lantern nights + the virtual contest, and the Spring Open
            self::assertSame(2, $exception->seriesCount);
            self::assertSame(1, $exception->eventCount);
        }

        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM organization WHERE id = :id', OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testAnEmptyOrganizationIsDeletedWithItsFollowsTeamAndRedirects(): void
    {
        $cedar = OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT;
        $this->connection->insert('followed_competition', [
            'id' => '018d0042-0000-0000-0000-0000000000f1',
            'player_id' => PlayerFixture::PLAYER_REGULAR,
            'organization_id' => $cedar,
            'created_at' => '2026-10-01 10:00:00',
        ]);
        $this->connection->insert('organization_maintainer', [
            'organization_id' => $cedar,
            'player_id' => PlayerFixture::PLAYER_REGULAR,
        ]);
        $this->connection->insert('event_url_redirect', [
            'id' => '018d0042-0000-0000-0000-0000000000f2',
            'series_slug' => 'old-cedar-series',
            'competition_slug' => '',
            'round_slug' => '',
            'organization_id' => $cedar,
            'created_at' => '2026-10-01 10:00:00',
        ]);

        $this->messageBus->dispatch(new DeleteOrganization($cedar));

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM organization WHERE id = :id', $cedar));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM followed_competition WHERE organization_id = :id', $cedar));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM organization_maintainer WHERE organization_id = :id', $cedar));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM event_url_redirect WHERE organization_id = :id', $cedar));
    }

    private function rows(string $sql, string $id): int
    {
        $count = $this->connection->fetchOne($sql, ['id' => $id]);

        return is_numeric($count) ? (int) $count : 0;
    }
}
