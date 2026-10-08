<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
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

    public function testFollowingTheSeriesFollowsItsEditions(): void
    {
        $edition = self::getContainer()->get(GetCompetitionEvents::class)->byId(EventsPageFixture::EDITION_HARBOR_1);

        // PLAYER_REGULAR follows Harbor Jigsaw Nights
        self::assertTrue($this->query->forEvent($edition, PlayerFixture::PLAYER_REGULAR, true)->isFollowing);
        self::assertFalse($this->query->forEvent($edition, PlayerFixture::PLAYER_WITH_FAVORITES, true)->isFollowing);
        self::assertFalse($this->query->forEvent($edition, null, true)->isFollowing);
    }

    public function testFollowingAnEditionItselfIsNotFollowingItsSeries(): void
    {
        // The edition page's star acts on the series: an old follow of the edition alone leaves it unpressed
        $this->database->executeStatement(
            "INSERT INTO followed_competition (id, created_at, player_id, competition_id, series_id) VALUES ('018d0040-0000-0000-0000-0000000000f1', NOW(), :player, :edition, NULL)",
            ['player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'edition' => EventsPageFixture::EDITION_HARBOR_1],
        );

        $edition = self::getContainer()->get(GetCompetitionEvents::class)->byId(EventsPageFixture::EDITION_HARBOR_1);

        self::assertFalse($this->query->forEvent($edition, PlayerFixture::PLAYER_WITH_FAVORITES, true)->isFollowing);
    }

    public function testFollowingAOneTimeEvent(): void
    {
        $meadow = self::getContainer()->get(GetCompetitionEvents::class)->byId(EventsPageFixture::COMPETITION_MEADOW_TBA);

        self::assertTrue($this->query->forEvent($meadow, PlayerFixture::PLAYER_REGULAR, true)->isFollowing);
        self::assertFalse($this->query->forEvent($meadow, PlayerFixture::PLAYER_ADMIN, true)->isFollowing);
    }

    public function testTheRegistrationStatementSaysWhoFollows(): void
    {
        $riverside = self::getContainer()->get(GetCompetitionEvents::class)->byId(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN);

        // Managed registration: one statement for the card and the follow flag - for a visitor too
        self::assertTrue($this->query->forEvent($riverside, PlayerFixture::PLAYER_WITH_FAVORITES, true)->isFollowing);
        self::assertNotNull($this->query->forEvent($riverside, null, true)->registration);
        self::assertFalse($this->query->forEvent($riverside, null, true)->isFollowing);
        self::assertFalse($this->query->forEvent($riverside, PlayerFixture::PLAYER_REGULAR, true)->isFollowing);
    }
}
