<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEventAttendanceTest extends KernelTestCase
{
    private GetEventAttendance $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetEventAttendance::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testVisitorIsNotGoing(): void
    {
        $attendance = $this->query->forPlayer(CompetitionFixture::COMPETITION_WJPC_2024, null);

        self::assertFalse($attendance->isGoing);
        self::assertFalse($attendance->canChangeParticipant);
    }

    public function testConnectedPlayerCanChangeWhileTheListHasUnclaimedNames(): void
    {
        // PLAYER_REGULAR is connected to 'John Regular', 'Jane Unconnected' is still unclaimed
        $attendance = $this->query->forPlayer(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($attendance->isGoing);
        self::assertTrue($attendance->canChangeParticipant);
    }

    public function testConnectedPlayerCannotChangeWhenNobodyIsLeftOnTheList(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        );

        $attendance = $this->query->forPlayer(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($attendance->isGoing);
        self::assertFalse($attendance->canChangeParticipant);
    }

    public function testSelfJoinedPlayerIsGoing(): void
    {
        $attendance = $this->query->forPlayer(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertTrue($attendance->isGoing);
    }

    public function testPlayerNotOnTheListIsNotGoingAndHasNothingToChange(): void
    {
        $attendance = $this->query->forPlayer(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_ADMIN);

        self::assertFalse($attendance->isGoing);
        self::assertFalse($attendance->canChangeParticipant);
    }

    public function testPlayerWhoLeftIsNotGoing(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED],
        );

        $attendance = $this->query->forPlayer(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertFalse($attendance->isGoing);
    }

    public function testAttendanceIsPerCompetition(): void
    {
        // Going to WJPC 2024 says nothing about an edition of a series
        $attendance = $this->query->forPlayer(CompetitionSeriesFixture::EDITION_EJJ_69, PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($attendance->isGoing);
    }
}
