<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\RejectOrganization;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RejectOrganizationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testItIsRejectedWithItsReasonAndTheCreatorIsTold(): void
    {
        $this->messageBus->dispatch(new RejectOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            rejectedByPlayerId: PlayerFixture::PLAYER_ADMIN,
            reason: 'Please add a website.',
        ));

        $organization = self::getContainer()->get(OrganizationRepository::class)->get(OrganizationFixture::ORGANIZATION_MAPLE_PENDING);

        self::assertTrue($organization->isRejected());
        self::assertSame('Please add a website.', $organization->rejectionReason);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $organization->rejectedByPlayer?->id->toString());

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage() ?? self::fail('No e-mail');
        self::assertEmailAddressContains($email, 'To', PlayerFixture::PLAYER_WITH_FAVORITES_EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'Your organization was not approved');
        self::assertEmailHtmlBodyContains($email, 'Please add a website.');
    }

    public function testItsSeriesKeepsItsOwnState(): void
    {
        $this->messageBus->dispatch(new RejectOrganization(
            organizationId: OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            rejectedByPlayerId: PlayerFixture::PLAYER_ADMIN,
            reason: 'Duplicate.',
        ));

        $series = self::getContainer()->get(CompetitionSeriesRepository::class)->get(OrganizationFixture::SERIES_MAPLE_PENDING);

        self::assertFalse($series->isRejected());
        self::assertFalse($series->isApproved());
    }
}
