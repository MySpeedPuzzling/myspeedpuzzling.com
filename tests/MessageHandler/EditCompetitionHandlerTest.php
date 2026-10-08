<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
    }

    public function testRenamingKeepsTheSlug(): void
    {
        $this->messageBus->dispatch($this->edit(name: 'Vienna Puzzle Days'));

        $competition = $this->competition();
        self::assertSame('Vienna Puzzle Days', $competition->name);
        self::assertSame('unapproved-puzzle-event', $competition->slug);
    }

    public function testWhoCanEnterIsSavedAndCleared(): void
    {
        $this->messageBus->dispatch($this->edit(name: 'Vienna Puzzle Days', eligibility: 'Residents of Vienna'));
        self::assertSame('Residents of Vienna', $this->competition()->eligibility);

        $this->messageBus->dispatch($this->edit(name: 'Vienna Puzzle Days'));
        self::assertNull($this->competition()->eligibility);
    }

    public function testAnExplicitSlugWins(): void
    {
        $this->messageBus->dispatch($this->edit(name: 'Vienna Puzzle Days', slug: 'vienna-2026'));

        self::assertSame('vienna-2026', $this->competition()->slug);
    }

    public function testAnExplicitSlugOfAnotherCompetitionIsRefused(): void
    {
        $this->expectException(CompetitionSlugTaken::class);

        $this->messageBus->dispatch($this->edit(name: 'Unapproved Puzzle Event', slug: 'wjpc-2024'));
    }

    private function edit(string $name, null|string $slug = null, null|string $eligibility = null): EditCompetition
    {
        $competition = $this->competition();

        return new EditCompetition(
            competitionId: CompetitionFixture::COMPETITION_UNAPPROVED,
            name: $name,
            shortcut: $competition->shortcut,
            description: $competition->description,
            link: $competition->link,
            registrationLink: $competition->registrationLink,
            resultsLink: $competition->resultsLink,
            location: $competition->location,
            locationCountryCode: $competition->locationCountryCode,
            dateFrom: $competition->dateFrom,
            dateTo: $competition->dateTo,
            isOnline: $competition->isOnline,
            logo: null,
            maintainerIds: [],
            eligibility: $eligibility,
            slug: $slug,
        );
    }

    private function competition(): Competition
    {
        return $this->competitionRepository->get(CompetitionFixture::COMPETITION_UNAPPROVED);
    }
}
