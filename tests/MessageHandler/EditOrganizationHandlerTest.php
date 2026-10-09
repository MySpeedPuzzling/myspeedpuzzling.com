<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OrganizationSlugTaken;
use SpeedPuzzling\Web\Message\EditOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditOrganizationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private OrganizationRepository $organizationRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->organizationRepository = self::getContainer()->get(OrganizationRepository::class);
    }

    public function testARenameKeepsTheSlugAndEveryFieldIsSaved(): void
    {
        $this->messageBus->dispatch($this->edit('Riverbend Valley Jigsaw Association', maintainerIds: [PlayerFixture::PLAYER_WITH_FAVORITES]));

        $organization = $this->organizationRepository->get(OrganizationFixture::ORGANIZATION_RIVERBEND);

        self::assertSame('Riverbend Valley Jigsaw Association', $organization->name);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $organization->slug);
        self::assertSame('RVJA', $organization->shortName);
        self::assertSame('New about text.', $organization->about);
        self::assertSame('https://riverbend-valley.example', $organization->website);
        self::assertSame(['https://www.youtube.com/@riverbendjigsaw'], $organization->socialLinks);
        self::assertSame('ca', $organization->countryCode);
        self::assertSame('Upper Riverbend', $organization->region);
        self::assertSame(OrganizationKind::Community, $organization->kind);
        // The logo stays when no new one is sent
        self::assertNull($organization->logo);
    }

    public function testTheTeamIsReplacedAndNeverHoldsTheCreator(): void
    {
        $this->messageBus->dispatch($this->edit('Riverbend Jigsaw Association', maintainerIds: [
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_WITH_STRIPE,
        ]));

        $maintainers = array_map(
            static fn (Player $player): string => $player->id->toString(),
            $this->organizationRepository->get(OrganizationFixture::ORGANIZATION_RIVERBEND)->maintainers->toArray(),
        );

        // PLAYER_WITH_FAVORITES is out, PLAYER_WITH_STRIPE (the creator) never becomes a maintainer row
        self::assertSame([PlayerFixture::PLAYER_REGULAR], array_values($maintainers));
    }

    public function testAnExplicitSlugChangesIt(): void
    {
        $this->messageBus->dispatch($this->edit('Riverbend Jigsaw Association', slug: 'rja'));

        self::assertSame('rja', $this->organizationRepository->get(OrganizationFixture::ORGANIZATION_RIVERBEND)->slug);
    }

    public function testItsOwnSlugIsNotTaken(): void
    {
        $this->messageBus->dispatch($this->edit('Riverbend Jigsaw Association', slug: OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG));

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $this->organizationRepository->get(OrganizationFixture::ORGANIZATION_RIVERBEND)->slug);
    }

    public function testASlugOfAnotherOrganizationIsRefused(): void
    {
        $this->expectException(OrganizationSlugTaken::class);

        $this->messageBus->dispatch($this->edit('Riverbend Jigsaw Association', slug: OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG));
    }

    public function testAnInvalidSlugIsRefused(): void
    {
        $this->expectException(InvalidCompetitionSlug::class);

        $this->messageBus->dispatch($this->edit('Riverbend Jigsaw Association', slug: 'Not A Slug'));
    }

    /**
     * @param list<string> $maintainerIds
     */
    private function edit(string $name, null|string $slug = null, array $maintainerIds = []): EditOrganization
    {
        return new EditOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND,
            name: $name,
            shortName: 'RVJA',
            about: 'New about text.',
            website: 'https://riverbend-valley.example',
            socialLinks: ['https://www.youtube.com/@riverbendjigsaw', ''],
            countryCode: 'CA',
            region: 'Upper Riverbend',
            kind: OrganizationKind::Community,
            logo: null,
            maintainerIds: $maintainerIds,
            slug: $slug,
        );
    }
}
