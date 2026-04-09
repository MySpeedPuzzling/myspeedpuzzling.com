<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class CreateCompetitionTeamHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionTeamRepository $teamRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->teamRepository = self::getContainer()->get(CompetitionTeamRepository::class);
    }

    public function testTeamCanBeCreatedWithName(): void
    {
        $teamId = Uuid::uuid7();

        $this->messageBus->dispatch(new CreateCompetitionTeam(
            teamId: $teamId,
            roundId: CompetitionSeriesFixture::ROUND_OFFLINE_TEAM,
            name: 'Team Alpha',
        ));

        $team = $this->teamRepository->get($teamId->toString());

        self::assertSame('Team Alpha', $team->name);
        self::assertSame(CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, $team->round->id->toString());
    }

    public function testTeamCanBeCreatedWithoutName(): void
    {
        $teamId = Uuid::uuid7();

        $this->messageBus->dispatch(new CreateCompetitionTeam(
            teamId: $teamId,
            roundId: CompetitionSeriesFixture::ROUND_OFFLINE_TEAM,
            name: null,
        ));

        $team = $this->teamRepository->get($teamId->toString());

        self::assertNull($team->name);
    }
}
