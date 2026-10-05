<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionTeamNameTooLong;
use SpeedPuzzling\Web\Message\CreateCompetitionTeams;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class CreateCompetitionTeamsHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testEveryNameBecomesATeam(): void
    {
        $before = $this->teamNames();

        $this->messageBus->dispatch(new CreateCompetitionTeams(
            roundId: CompetitionSeriesFixture::ROUND_OFFLINE_TEAM,
            names: ['Team Alpha', 'Team Beta'],
        ));

        self::assertSame(['Team Alpha', 'Team Beta'], array_values(array_diff($this->teamNames(), $before)));
    }

    public function testNoNameAddsOneUnnamedTeam(): void
    {
        $before = $this->teamCount();

        $this->messageBus->dispatch(new CreateCompetitionTeams(
            roundId: CompetitionSeriesFixture::ROUND_OFFLINE_TEAM,
            names: [],
        ));

        self::assertSame($before + 1, $this->teamCount());
        self::assertContains(null, $this->teamNames());
    }

    public function testTooLongNameAddsNoTeamAtAll(): void
    {
        $before = $this->teamCount();

        try {
            $this->messageBus->dispatch(new CreateCompetitionTeams(
                roundId: CompetitionSeriesFixture::ROUND_OFFLINE_TEAM,
                names: ['Team Gamma', str_repeat('x', CompetitionTeam::NAME_MAX_LENGTH + 1)],
            ));
            self::fail('A team name longer than the column must be refused.');
        } catch (CompetitionTeamNameTooLong) {
        }

        self::assertSame($before, $this->teamCount());
    }

    /**
     * @return list<null|string>
     */
    private function teamNames(): array
    {
        /** @var list<null|string> */
        return $this->database->executeQuery(
            'SELECT name FROM competition_team WHERE round_id = :roundId ORDER BY name',
            ['roundId' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM],
        )->fetchFirstColumn();
    }

    private function teamCount(): int
    {
        return count($this->teamNames());
    }
}
