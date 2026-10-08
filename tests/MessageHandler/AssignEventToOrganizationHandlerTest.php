<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\Exceptions\OrganizationOnEdition;
use SpeedPuzzling\Web\Message\AssignEventToOrganization;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OrganizationItemKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/organizations/README.md "Permissions" and "Approval" (D2): moving into an organization needs its team or
 * an admin; a pending item moved under an approved organization by its team is approved at once.
 */
final class AssignEventToOrganizationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;
    private CompetitionSeriesRepository $seriesRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
        $this->seriesRepository = self::getContainer()->get(CompetitionSeriesRepository::class);
    }

    public function testATeamMemberMovesAPendingEventInAndItIsApprovedAtOnce(): void
    {
        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Competition,
            itemId: CompetitionFixture::COMPETITION_UNAPPROVED,
            organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND,
            actingPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        $competition = $this->competitionRepository->get(CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $competition->organization?->id->toString());
        self::assertTrue($competition->isApproved());
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $competition->approvedByPlayer?->id->toString());
    }

    public function testATeamMemberMovesAPendingSeriesInAndItIsApprovedAtOnce(): void
    {
        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Series,
            itemId: CompetitionSeriesFixture::SERIES_UNAPPROVED,
            organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        ));

        $series = $this->seriesRepository->get(CompetitionSeriesFixture::SERIES_UNAPPROVED);

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $series->organization?->id->toString());
        self::assertTrue($series->isApproved());
    }

    public function testSomebodyOutsideTheTeamCannotMoveAnythingIn(): void
    {
        $this->expectException(OrganizationNotManaged::class);

        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Competition,
            itemId: CompetitionFixture::COMPETITION_UNAPPROVED,
            organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND,
            actingPlayerId: PlayerFixture::PLAYER_REGULAR,
        ));
    }

    public function testAnAdminMovesIntoAnyOrganization(): void
    {
        // The admin is not on the Harbor Puzzle Club's team; the club is approved (a draft - it hides only itself)
        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Competition,
            itemId: CompetitionFixture::COMPETITION_UNAPPROVED,
            organizationId: OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT,
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $competition = $this->competitionRepository->get(CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertSame(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, $competition->organization?->id->toString());
        self::assertTrue($competition->isApproved());
    }

    public function testUnderAPendingOrganizationNothingIsApproved(): void
    {
        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Competition,
            itemId: CompetitionFixture::COMPETITION_UNAPPROVED,
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            actingPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        $competition = $this->competitionRepository->get(CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertSame(OrganizationFixture::ORGANIZATION_MAPLE_PENDING, $competition->organization?->id->toString());
        self::assertFalse($competition->isApproved());
    }

    public function testAnEditionNeverGetsAnOrganization(): void
    {
        $this->expectException(OrganizationOnEdition::class);

        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Competition,
            itemId: CompetitionSeriesFixture::EDITION_EJJ_68,
            organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND,
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));
    }

    public function testARejectedEventStaysRejected(): void
    {
        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Competition,
            itemId: EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED,
            organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND,
            actingPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        $competition = $this->competitionRepository->get(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED);

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $competition->organization?->id->toString());
        self::assertTrue($competition->isRejected());
        self::assertFalse($competition->isApproved());
    }

    public function testMovingOutKeepsTheApproval(): void
    {
        // Moving out needs no team membership - only the right to edit the item, which the caller checks
        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Competition,
            itemId: OrganizationFixture::COMPETITION_RIVERBEND_OPEN,
            organizationId: null,
            actingPlayerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $competition = $this->competitionRepository->get(OrganizationFixture::COMPETITION_RIVERBEND_OPEN);

        self::assertNull($competition->organization);
        self::assertTrue($competition->isApproved());
    }

    public function testASeriesMovesOut(): void
    {
        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Series,
            itemId: OrganizationFixture::SERIES_LANTERN_NIGHTS,
            organizationId: null,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        ));

        $series = $this->seriesRepository->get(OrganizationFixture::SERIES_LANTERN_NIGHTS);

        self::assertNull($series->organization);
        self::assertTrue($series->isApproved());
    }
}
