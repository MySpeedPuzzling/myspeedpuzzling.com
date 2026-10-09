<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Organizations;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Organizations\OrganizationApprovalPolicy;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/features/organizations/implementation-plan.md 1.8 - D2 (under a trusted organization, its team needs no admin
 * approval) and P2 (approving an organization approves its pending items). Entities are built in memory: the policy only
 * reads them (its official-results lookup finds nothing for an unknown id).
 */
final class OrganizationApprovalPolicyTest extends KernelTestCase
{
    private OrganizationApprovalPolicy $policy;
    private DateTimeImmutable $now;
    private Player $creator;
    private Player $maintainer;
    private Player $stranger;
    private Player $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->policy = self::getContainer()->get(OrganizationApprovalPolicy::class);
        $this->now = new DateTimeImmutable('2026-10-08 12:00:00');

        $players = self::getContainer()->get(PlayerRepository::class);
        $this->creator = $players->get(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->maintainer = $players->get(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->stranger = $players->get(PlayerFixture::PLAYER_REGULAR);
        $this->admin = $players->get(PlayerFixture::PLAYER_ADMIN);
    }

    public function testAnEventWithoutAnOrganizationIsLeftAlone(): void
    {
        $event = $this->event(null);

        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($event, $this->creator, $this->now));
        self::assertFalse($event->isApproved());
    }

    public function testAnOrganizationWaitingForApprovalIsNotTrusted(): void
    {
        $event = $this->event($this->organization(approved: false));

        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($event, $this->creator, $this->now));
        self::assertFalse($event->isApproved());
    }

    public function testARejectedOrganizationIsNotTrusted(): void
    {
        $organization = $this->organization();
        $organization->reject($this->admin, $this->now, 'Duplicate');
        $event = $this->event($organization);

        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($event, $this->creator, $this->now));
        self::assertFalse($event->isApproved());
    }

    public function testAnEditionIsNeverApprovedOnItsOwn(): void
    {
        $event = $this->event($this->organization());
        // Only reachable around the entity's guard - an edition never has an organization of its own
        $event->series = $this->series(null);

        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($event, $this->creator, $this->now));
        self::assertFalse($event->isApproved());
    }

    public function testAnApprovedEventKeepsItsApproval(): void
    {
        $event = $this->event($this->organization());
        $earlier = new DateTimeImmutable('2026-01-01 10:00:00');
        $event->approve($this->admin, $earlier);

        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($event, $this->creator, $this->now));
        self::assertSame($earlier, $event->approvedAt);
        self::assertSame($this->admin, $event->approvedByPlayer);
    }

    public function testARejectedEventStaysRejected(): void
    {
        $event = $this->event($this->organization());
        $event->reject($this->admin, $this->now, 'Not a puzzle event');

        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($event, $this->creator, $this->now));
        self::assertFalse($event->isApproved());
        self::assertTrue($event->isRejected());
    }

    public function testSomebodyOutsideTheTeamGetsNoApproval(): void
    {
        $event = $this->event($this->organization());

        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($event, $this->stranger, $this->now));
        self::assertFalse($event->isApproved());
    }

    public function testAnAdminIsTrustedEverywhere(): void
    {
        $event = $this->event($this->organization());

        self::assertTrue($this->policy->approveIfUnderTrustedOrganization($event, $this->admin, $this->now));
        self::assertSame($this->now, $event->approvedAt);
        self::assertSame($this->admin, $event->approvedByPlayer);
    }

    public function testTheCreatorAndAMaintainerAreTrusted(): void
    {
        $organization = $this->organization();

        $byCreator = $this->event($organization);
        self::assertTrue($this->policy->approveIfUnderTrustedOrganization($byCreator, $this->creator, $this->now));
        self::assertSame($this->creator, $byCreator->approvedByPlayer);

        $byMaintainer = $this->event($organization);
        self::assertTrue($this->policy->approveIfUnderTrustedOrganization($byMaintainer, $this->maintainer, $this->now));
        self::assertSame($this->maintainer, $byMaintainer->approvedByPlayer);
    }

    public function testADraftIsApprovedAndStaysHidden(): void
    {
        $event = $this->event($this->organization());
        $event->unpublish();

        self::assertTrue($this->policy->approveIfUnderTrustedOrganization($event, $this->maintainer, $this->now));
        self::assertTrue($event->isApproved());
        self::assertFalse($event->isPubliclyVisible());
    }

    public function testADraftOrganizationIsTrustedToo(): void
    {
        $organization = $this->organization();
        $organization->unpublish();
        $event = $this->event($organization);

        self::assertTrue($this->policy->approveIfUnderTrustedOrganization($event, $this->creator, $this->now));
    }

    public function testASeriesFollowsTheSameRules(): void
    {
        $trusted = $this->organization();

        $byMaintainer = $this->series($trusted);
        self::assertTrue($this->policy->approveIfUnderTrustedOrganization($byMaintainer, $this->maintainer, $this->now));
        self::assertTrue($byMaintainer->isApproved());

        $byStranger = $this->series($trusted);
        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($byStranger, $this->stranger, $this->now));
        self::assertFalse($byStranger->isApproved());

        $withoutOrganization = $this->series(null);
        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($withoutOrganization, $this->admin, $this->now));

        $underPending = $this->series($this->organization(approved: false));
        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($underPending, $this->admin, $this->now));

        $rejected = $this->series($trusted);
        $rejected->reject($this->admin, $this->now, 'Duplicate');
        self::assertFalse($this->policy->approveIfUnderTrustedOrganization($rejected, $this->maintainer, $this->now));
        self::assertFalse($rejected->isApproved());
    }

    public function testApprovingAnOrganizationApprovesItsPendingItemsOnly(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        // Under Maple (pending, with a pending series): a pending one-time event and a rejected one
        foreach ([CompetitionFixture::COMPETITION_UNAPPROVED, EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED] as $competitionId) {
            $connection->executeStatement(
                'UPDATE competition SET organization_id = :organization WHERE id = :competition',
                ['organization' => OrganizationFixture::ORGANIZATION_MAPLE_PENDING, 'competition' => $competitionId],
            );
        }

        $maple = self::getContainer()->get(OrganizationRepository::class)->get(OrganizationFixture::ORGANIZATION_MAPLE_PENDING);

        $this->policy->approvePendingItemsOf($maple, $this->admin, $this->now);

        $series = self::getContainer()->get(CompetitionSeriesRepository::class)->get(OrganizationFixture::SERIES_MAPLE_PENDING);
        self::assertTrue($series->isApproved());
        self::assertSame($this->admin, $series->approvedByPlayer);

        $competitions = self::getContainer()->get(CompetitionRepository::class);
        self::assertTrue($competitions->get(CompetitionFixture::COMPETITION_UNAPPROVED)->isApproved());

        $rejected = $competitions->get(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED);
        self::assertFalse($rejected->isApproved());
        self::assertTrue($rejected->isRejected());

        // Its edition is approved through the series, never on its own
        self::assertFalse($competitions->get(OrganizationFixture::EDITION_MAPLE_PENDING_1)->isApproved());
    }

    private function organization(bool $approved = true): Organization
    {
        return new Organization(
            id: Uuid::uuid7(),
            name: 'Willowmere Puzzle Guild',
            slug: 'willowmere-puzzle-guild-' . Uuid::uuid7()->toString(),
            createdAt: $this->now,
            addedByPlayer: $this->creator,
            approvedAt: $approved ? $this->now : null,
            approvedByPlayer: $approved ? $this->admin : null,
            maintainers: new ArrayCollection([$this->maintainer]),
        );
    }

    private function event(null|Organization $organization): Competition
    {
        return new Competition(
            id: Uuid::uuid7(),
            name: 'Willowmere Spring Cup',
            slug: 'willowmere-spring-cup',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Willowmere',
            locationCountryCode: 'us',
            dateFrom: null,
            dateTo: null,
            tag: null,
            addedByPlayer: $this->creator,
            createdAt: $this->now,
            organization: $organization,
        );
    }

    private function series(null|Organization $organization): CompetitionSeries
    {
        return new CompetitionSeries(
            id: Uuid::uuid7(),
            name: 'Willowmere Puzzle Evenings',
            slug: 'willowmere-puzzle-evenings',
            logo: null,
            description: null,
            link: null,
            addedByPlayer: $this->creator,
            createdAt: $this->now,
            organization: $organization,
        );
    }
}
