<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\OrganizationNotApprovable;
use SpeedPuzzling\Web\Message\ApproveOrganization;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/organizations/README.md "Approval": approving an organization approves its pending series and one-time
 * events too (P2) - never the rejected ones.
 */
final class ApproveOrganizationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testTheOrganizationAndItsPendingItemsAreApproved(): void
    {
        // A pending one-time event and a pending series put under Maple as well
        $this->connection->executeStatement(
            'UPDATE competition SET organization_id = :organization WHERE id = :competition',
            ['organization' => OrganizationFixture::ORGANIZATION_MAPLE_PENDING, 'competition' => CompetitionFixture::COMPETITION_UNAPPROVED],
        );
        $this->connection->executeStatement(
            'UPDATE competition_series SET organization_id = :organization WHERE id = :series',
            ['organization' => OrganizationFixture::ORGANIZATION_MAPLE_PENDING, 'series' => CompetitionSeriesFixture::SERIES_UNAPPROVED],
        );

        $this->messageBus->dispatch(new ApproveOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $organization = self::getContainer()->get(OrganizationRepository::class)->get(OrganizationFixture::ORGANIZATION_MAPLE_PENDING);
        self::assertTrue($organization->isApproved());
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $organization->approvedByPlayer?->id->toString());

        $seriesRepository = self::getContainer()->get(CompetitionSeriesRepository::class);

        foreach ([OrganizationFixture::SERIES_MAPLE_PENDING, CompetitionSeriesFixture::SERIES_UNAPPROVED] as $seriesId) {
            $series = $seriesRepository->get($seriesId);
            self::assertTrue($series->isApproved(), $seriesId);
            self::assertSame(PlayerFixture::PLAYER_ADMIN, $series->approvedByPlayer?->id->toString());
        }

        self::assertTrue(self::getContainer()->get(CompetitionRepository::class)->get(CompetitionFixture::COMPETITION_UNAPPROVED)->isApproved());
    }

    public function testARejectedItemUnderItStaysRejected(): void
    {
        $this->connection->executeStatement(
            'UPDATE competition SET organization_id = :organization WHERE id = :competition',
            ['organization' => OrganizationFixture::ORGANIZATION_MAPLE_PENDING, 'competition' => EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED],
        );

        $this->messageBus->dispatch(new ApproveOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $competition = self::getContainer()->get(CompetitionRepository::class)->get(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED);

        self::assertFalse($competition->isApproved());
        self::assertTrue($competition->isRejected());
    }

    public function testTheCreatorIsTold(): void
    {
        $this->messageBus->dispatch(new ApproveOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage() ?? self::fail('No e-mail');
        self::assertEmailAddressContains($email, 'To', PlayerFixture::PLAYER_WITH_FAVORITES_EMAIL);
        // The organization's own e-mail - never the event one ("Your event ...")
        self::assertEmailHeaderSame($email, 'Subject', 'Your organization has been approved!');
        self::assertEmailHtmlBodyContains($email, '/en/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG);
    }

    public function testTheCreatorIsNotToldWhenLeftOut(): void
    {
        $this->messageBus->dispatch(new ApproveOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
            notifyCreator: false,
        ));

        self::assertQueuedEmailCount(0);
        self::assertTrue(self::getContainer()->get(OrganizationRepository::class)->get(OrganizationFixture::ORGANIZATION_MAPLE_PENDING)->isApproved());
    }

    public function testAnApprovedOrganizationIsNotApprovedAgain(): void
    {
        $this->expectException(OrganizationNotApprovable::class);

        $this->messageBus->dispatch(new ApproveOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));
    }

    public function testARejectedOrganizationIsNotApproved(): void
    {
        $this->connection->executeStatement(
            "UPDATE organization SET rejected_at = NOW(), rejection_reason = 'Duplicate' WHERE id = :id",
            ['id' => OrganizationFixture::ORGANIZATION_MAPLE_PENDING],
        );

        $this->expectException(OrganizationNotApprovable::class);

        $this->messageBus->dispatch(new ApproveOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));
    }
}
