<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionTeam;
use SpeedPuzzling\Web\Query\GetRoundTeams;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetRoundTeamsTest extends KernelTestCase
{
    private GetRoundTeams $query;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetRoundTeams::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testEmptyRoundReturnsNoTeams(): void
    {
        $teams = $this->query->forRound(CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);

        self::assertSame([], $teams);
    }

    public function testCreatedTeamAppearsInResults(): void
    {
        $teamId = Uuid::uuid7();

        $this->messageBus->dispatch(new CreateCompetitionTeam(
            teamId: $teamId,
            roundId: CompetitionSeriesFixture::ROUND_OFFLINE_TEAM,
            name: 'Test Team',
        ));

        $teams = $this->query->forRound(CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);

        self::assertCount(1, $teams);
        self::assertSame('Test Team', $teams[0]->name);
        self::assertSame([], $teams[0]->members);
    }

    public function testUnassignedParticipantsForEmptyRound(): void
    {
        $unassigned = $this->query->unassignedParticipants(CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);

        // No participants assigned to this round yet
        self::assertSame([], $unassigned);
    }
}
