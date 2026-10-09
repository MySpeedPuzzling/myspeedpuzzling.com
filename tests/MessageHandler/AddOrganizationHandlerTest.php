<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OrganizationSlugTaken;
use SpeedPuzzling\Web\Message\AddOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddOrganizationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private OrganizationRepository $organizationRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->organizationRepository = self::getContainer()->get(OrganizationRepository::class);
    }

    public function testANewOrganizationWaitsForApprovalAndTheAdminIsAsked(): void
    {
        $organizationId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($organizationId, 'Prairie Puzzle Society'));

        $organization = $this->organizationRepository->get($organizationId->toString());

        self::assertSame('prairie-puzzle-society', $organization->slug);
        self::assertSame('PPS', $organization->shortName);
        self::assertSame(OrganizationKind::Club, $organization->kind);
        self::assertSame('us', $organization->countryCode);
        self::assertSame('Prairie County', $organization->region);
        self::assertSame(['https://www.instagram.com/prairiepuzzles', 'https://discord.gg/prairiepuzzles'], $organization->socialLinks);
        self::assertNull($organization->logo);
        self::assertFalse($organization->isDraft);
        self::assertFalse($organization->isApproved());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $organization->addedByPlayer?->id->toString());

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage() ?? self::fail('No e-mail');
        self::assertEmailAddressContains($email, 'To', 'jan.mikes@myspeedpuzzling.com');
        self::assertEmailHeaderSame($email, 'Subject', 'New organization submitted: Prairie Puzzle Society');
    }

    public function testTheTeamNeverHoldsTheCreatorAsAMaintainer(): void
    {
        $organizationId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($organizationId, 'Prairie Puzzle Society', maintainerIds: [
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        ]));

        $maintainers = array_map(
            static fn (Player $player): string => $player->id->toString(),
            $this->organizationRepository->get($organizationId->toString())->maintainers->toArray(),
        );

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], array_values($maintainers));
    }

    public function testADraftIsNotSubmittedYet(): void
    {
        $organizationId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($organizationId, 'Prairie Puzzle Society', isDraft: true));

        self::assertTrue($this->organizationRepository->get($organizationId->toString())->isDraft);
        self::assertQueuedEmailCount(0);
    }

    public function testTheInternalApiCreatesItApprovedByItsCreatorWithoutAnEmail(): void
    {
        $organizationId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($organizationId, 'Prairie Puzzle Society', approve: true));

        $organization = $this->organizationRepository->get($organizationId->toString());

        self::assertTrue($organization->isApproved());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $organization->approvedByPlayer?->id->toString());
        self::assertQueuedEmailCount(0);
    }

    public function testAnExplicitSlugIsKept(): void
    {
        $organizationId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($organizationId, 'Prairie Puzzle Society', slug: 'pps'));

        self::assertSame('pps', $this->organizationRepository->get($organizationId->toString())->slug);
    }

    public function testAGeneratedSlugAvoidsATakenOne(): void
    {
        $organizationId = Uuid::uuid7();

        $this->messageBus->dispatch($this->add($organizationId, OrganizationFixture::ORGANIZATION_RIVERBEND_NAME));

        $slug = $this->organizationRepository->get($organizationId->toString())->slug;

        self::assertNotSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $slug);
        self::assertStringStartsWith(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG . '-', $slug);
    }

    public function testATakenExplicitSlugIsRefused(): void
    {
        $this->expectException(OrganizationSlugTaken::class);

        $this->messageBus->dispatch($this->add(Uuid::uuid7(), 'Prairie Puzzle Society', slug: OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG));
    }

    public function testAnInvalidExplicitSlugIsRefused(): void
    {
        $this->expectException(InvalidCompetitionSlug::class);

        $this->messageBus->dispatch($this->add(Uuid::uuid7(), 'Prairie Puzzle Society', slug: 'Not A Slug'));
    }

    /**
     * @param list<string> $maintainerIds
     */
    private function add(
        UuidInterface $organizationId,
        string $name,
        null|string $slug = null,
        bool $isDraft = false,
        bool $approve = false,
        array $maintainerIds = [],
    ): AddOrganization {
        return new AddOrganization(
            organizationId: $organizationId,
            playerId: PlayerFixture::PLAYER_REGULAR,
            name: $name,
            shortName: 'PPS',
            about: 'Puzzles on the prairie.',
            website: 'https://prairie-puzzles.example',
            socialLinks: ['https://www.instagram.com/prairiepuzzles', ' https://discord.gg/prairiepuzzles ', 'https://www.instagram.com/PrairiePuzzles'],
            countryCode: 'US',
            region: 'Prairie County',
            kind: OrganizationKind::Club,
            logo: null,
            maintainerIds: $maintainerIds,
            slug: $slug,
            isDraft: $isDraft,
            approve: $approve,
        );
    }
}
