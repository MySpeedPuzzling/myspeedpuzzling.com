<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
    }

    public function testASubmissionAsksTheAdminToReviewIt(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($competitionId, 'Brno Puzzle Weekend'));

        self::assertSame('brno-puzzle-weekend', $this->competitionRepository->get($competitionId->toString())->slug);
        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage() ?? self::fail('No e-mail'), 'To', 'jan.mikes@myspeedpuzzling.com');
    }

    public function testNoReviewRequestWhenLeftOut(): void
    {
        $this->messageBus->dispatch($this->add(Uuid::uuid7(), 'Admin Weekend', notifyAdmin: false));

        self::assertQueuedEmailCount(0);
    }

    public function testAnExplicitSlugIsKeptAndMustBeFree(): void
    {
        $competitionId = Uuid::uuid7();
        $this->messageBus->dispatch($this->add($competitionId, 'Brno Puzzle Weekend', slug: 'bpw-2026'));
        self::assertSame('bpw-2026', $this->competitionRepository->get($competitionId->toString())->slug);

        $this->expectException(CompetitionSlugTaken::class);
        $this->messageBus->dispatch($this->add(Uuid::uuid7(), 'Another Weekend', slug: 'wjpc-2024'));
    }

    public function testUnderAnApprovedOrganizationItsTeamNeedsNoApproval(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add(
            $competitionId,
            'Riverbend Autumn Open',
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND,
            eligibility: 'Residents of Riverbend Valley',
        ));

        $competition = $this->competitionRepository->get($competitionId->toString());

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $competition->organization?->id->toString());
        self::assertTrue($competition->isApproved());
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $competition->approvedByPlayer?->id->toString());
        self::assertSame('Residents of Riverbend Valley', $competition->eligibility);
        self::assertFalse($competition->isDraft);
        // Approved at once - nothing for the admin to review
        self::assertQueuedEmailCount(0);
    }

    public function testUnderAPendingOrganizationItStillWaitsForApproval(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add(
            $competitionId,
            'Maple Autumn Open',
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
        ));

        self::assertFalse($this->competitionRepository->get($competitionId->toString())->isApproved());
        self::assertQueuedEmailCount(1);
    }

    public function testOnlyTheTeamPutsAnEventUnderAnOrganization(): void
    {
        $this->expectException(OrganizationNotManaged::class);

        $this->messageBus->dispatch($this->add(Uuid::uuid7(), 'Riverbend Autumn Open', organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testAnAdminPutsAnEventUnderAnyOrganization(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($competitionId, 'Riverbend Autumn Open', playerId: PlayerFixture::PLAYER_ADMIN, organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND, notifyAdmin: false));

        self::assertTrue($this->competitionRepository->get($competitionId->toString())->isApproved());
    }

    public function testADraftIsNotSubmittedYet(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($competitionId, 'Brno Draft Weekend', isDraft: true));

        $competition = $this->competitionRepository->get($competitionId->toString());

        self::assertTrue($competition->isDraft);
        self::assertFalse($competition->isApproved());
        self::assertQueuedEmailCount(0);
    }

    private function add(
        \Ramsey\Uuid\UuidInterface $competitionId,
        string $name,
        null|string $slug = null,
        bool $notifyAdmin = true,
        string $playerId = PlayerFixture::PLAYER_REGULAR,
        null|string $organizationId = null,
        null|string $eligibility = null,
        bool $isDraft = false,
    ): AddCompetition {
        return new AddCompetition(
            competitionId: $competitionId,
            playerId: $playerId,
            name: $name,
            shortcut: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Brno',
            locationCountryCode: 'cz',
            dateFrom: null,
            dateTo: null,
            isOnline: false,
            logo: null,
            maintainerIds: [],
            slug: $slug,
            notifyAdmin: $notifyAdmin,
            organizationId: $organizationId,
            eligibility: $eligibility,
            isDraft: $isDraft,
        );
    }
}
