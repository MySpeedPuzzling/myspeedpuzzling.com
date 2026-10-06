<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
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

    private function add(\Ramsey\Uuid\UuidInterface $competitionId, string $name, null|string $slug = null, bool $notifyAdmin = true): AddCompetition
    {
        return new AddCompetition(
            competitionId: $competitionId,
            playerId: PlayerFixture::PLAYER_REGULAR,
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
        );
    }
}
