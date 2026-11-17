<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\EventNotLinkable;
use SpeedPuzzling\Web\Message\ApplyWaitingEventLinks;
use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Message\ApproveCompetitionSeries;
use SpeedPuzzling\Web\Message\LinkSolvingTimesToEvent;
use SpeedPuzzling\Web\Results\LinkedSolvingTimes;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * "Link my results" of the creation wizard's backfill path (docs/features/competitions-management/creation-wizard.md
 * "Backfill"): LinkSolvingTimesToEvent links the actor's own times - all or nothing, through EventLinkRule - and a link to
 * an event that is not public yet waits (WaitingEventLink) until ApplyWaitingEventLinks finds it public.
 */
final class LinkSolvingTimesToEventHandlerTest extends KernelTestCase
{
    private MessageBusInterface $bus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAPublicEventIsLinkedAtOnceAndItsRoundDerived(): void
    {
        // TIME_01: PLAYER_REGULAR's solo PUZZLE_500_01 - the Czech final round's puzzle (solo)
        $result = $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, [PuzzleSolvingTimeFixture::TIME_01]);

        self::assertSame(1, $result->linked);
        self::assertSame(0, $result->waiting);

        $row = $this->time(PuzzleSolvingTimeFixture::TIME_01);
        self::assertSame(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $row['competition_id']);
        self::assertNull($row['competition_series_id']);
        self::assertSame(CompetitionRoundFixture::ROUND_CZECH_FINAL, $row['competition_round_id']);

        // Sent again: already linked, nothing changes
        $again = $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, [PuzzleSolvingTimeFixture::TIME_01]);
        self::assertSame(1, $again->alreadyLinked);
        self::assertSame(0, $again->linked);
    }

    public function testATeamMemberMayLinkTheTeamsTime(): void
    {
        // TIME_12 is tracked by PLAYER_REGULAR, PLAYER_PRIVATE is the pair's other member
        $result = $this->link(PlayerFixture::PLAYER_PRIVATE, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, [PuzzleSolvingTimeFixture::TIME_12]);

        self::assertSame(1, $result->linked);
        self::assertSame(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $this->time(PuzzleSolvingTimeFixture::TIME_12)['competition_id']);
    }

    public function testSomebodyElsesTimeRefusesTheWholeMessage(): void
    {
        // TIME_02 is PLAYER_PRIVATE's - and TIME_06, PLAYER_REGULAR's own, must stay unlinked too (all or nothing)
        $this->assertRefused(CanNotModifyOtherPlayersTime::class, fn () => $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, [PuzzleSolvingTimeFixture::TIME_06, PuzzleSolvingTimeFixture::TIME_02]));

        self::assertNull($this->time(PuzzleSolvingTimeFixture::TIME_06)['competition_id']);
        self::assertNull($this->time(PuzzleSolvingTimeFixture::TIME_02)['competition_id']);
    }

    public function testAnImportedEventResultIsRefused(): void
    {
        $this->connection->executeStatement("UPDATE puzzle_solving_time SET created_via = 'event_import' WHERE id = :id", ['id' => PuzzleSolvingTimeFixture::TIME_07]);

        $this->assertRefused(CanNotModifyOtherPlayersTime::class, fn () => $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, [PuzzleSolvingTimeFixture::TIME_07]));
        self::assertNull($this->time(PuzzleSolvingTimeFixture::TIME_07)['competition_id']);
    }

    public function testSomebodyElsesDraftIsRefused(): void
    {
        // Birchwood Draft Night is PLAYER_WITH_STRIPE's draft
        $this->assertRefused(EventNotLinkable::class, fn () => $this->link(PlayerFixture::PLAYER_REGULAR, OrganizationFixture::COMPETITION_DRAFT_NIGHT, null, [PuzzleSolvingTimeFixture::TIME_06]));
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM waiting_event_link'));
    }

    public function testSomebodyElsesPendingEventIsRefused(): void
    {
        // The unapproved event is PLAYER_REGULAR's - PLAYER_WITH_FAVORITES may not link to it before it is public
        $this->assertRefused(EventNotLinkable::class, fn () => $this->link(PlayerFixture::PLAYER_WITH_FAVORITES, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_04]));
    }

    public function testTheOwnPendingEventWaitsAndIsAppliedOnceApproved(): void
    {
        $result = $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_06, PuzzleSolvingTimeFixture::TIME_08]);

        self::assertSame(0, $result->linked);
        self::assertSame(2, $result->waiting);
        // Never linked to an event nobody may see
        self::assertNull($this->time(PuzzleSolvingTimeFixture::TIME_06)['competition_id']);
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM waiting_event_link WHERE competition_id = :id', ['id' => CompetitionFixture::COMPETITION_UNAPPROVED]));

        // Linked again while waiting: still one row per time
        $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_06]);
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM waiting_event_link'));

        // Not public yet: nothing applied
        self::assertSame(['applied' => 0, 'dropped' => 0], $this->apply());

        // TIME_08 linked elsewhere meanwhile (the edit form) - that link stays, its waiting row goes
        $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, [PuzzleSolvingTimeFixture::TIME_08]);
        self::assertSame(1, $this->number('SELECT COUNT(*) FROM waiting_event_link'), 'A link made now replaces the waiting one');

        $this->connection->executeStatement('UPDATE competition SET approved_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_UNAPPROVED]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(['applied' => 1, 'dropped' => 0], $this->apply());
        self::assertSame(CompetitionFixture::COMPETITION_UNAPPROVED, $this->time(PuzzleSolvingTimeFixture::TIME_06)['competition_id']);
        self::assertSame(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $this->time(PuzzleSolvingTimeFixture::TIME_08)['competition_id']);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM waiting_event_link'));
    }

    public function testTheApprovalLinksTheWaitingTimesAtOnce(): void
    {
        $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_06]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        // No cron: the admin's approval itself links it, in its transaction
        $this->bus->dispatch(new ApproveCompetition(CompetitionFixture::COMPETITION_UNAPPROVED, PlayerFixture::PLAYER_ADMIN, notifyCreator: false));

        self::assertSame(CompetitionFixture::COMPETITION_UNAPPROVED, $this->time(PuzzleSolvingTimeFixture::TIME_06)['competition_id']);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM waiting_event_link'));
    }

    public function testASeriesApprovalLinksTheWaitingTimesOfItsDates(): void
    {
        // Maple Leaf Puzzle Evenings waits for approval; PLAYER_REGULAR is made its creator so he may link to its date
        $this->connection->executeStatement('UPDATE competition_series SET added_by_player_id = :p WHERE id = :s', ['p' => PlayerFixture::PLAYER_REGULAR, 's' => OrganizationFixture::SERIES_MAPLE_PENDING]);
        $this->connection->executeStatement('UPDATE competition SET added_by_player_id = :p WHERE id = :c', ['p' => PlayerFixture::PLAYER_REGULAR, 'c' => OrganizationFixture::EDITION_MAPLE_PENDING_1]);
        $result = $this->link(PlayerFixture::PLAYER_REGULAR, OrganizationFixture::EDITION_MAPLE_PENDING_1, null, [PuzzleSolvingTimeFixture::TIME_06]);
        self::assertSame(1, $result->waiting);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->bus->dispatch(new ApproveCompetitionSeries(OrganizationFixture::SERIES_MAPLE_PENDING, PlayerFixture::PLAYER_ADMIN, notifyCreator: false));

        self::assertSame(OrganizationFixture::EDITION_MAPLE_PENDING_1, $this->time(PuzzleSolvingTimeFixture::TIME_06)['competition_id']);
    }

    public function testATimeLinkedElsewhereMeanwhileKeepsItsLink(): void
    {
        $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_06]);
        // The edit form (or anything) linked it meanwhile
        $this->connection->executeStatement('UPDATE puzzle_solving_time SET competition_id = :c WHERE id = :id', ['c' => CompetitionFixture::COMPETITION_WJPC_2024, 'id' => PuzzleSolvingTimeFixture::TIME_06]);
        $this->connection->executeStatement('UPDATE competition SET approved_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_UNAPPROVED]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(['applied' => 0, 'dropped' => 1], $this->apply());
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $this->time(PuzzleSolvingTimeFixture::TIME_06)['competition_id']);
    }

    public function testARejectedEventsWaitingLinksAreDropped(): void
    {
        $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_06]);
        $this->connection->executeStatement('UPDATE competition SET rejected_at = ' . TestClock::SQL_NOW . ", rejection_reason = 'no' WHERE id = :id", ['id' => CompetitionFixture::COMPETITION_UNAPPROVED]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(['applied' => 0, 'dropped' => 1], $this->apply());
        self::assertNull($this->time(PuzzleSolvingTimeFixture::TIME_06)['competition_id']);

        // ... and a rejected event can no longer be linked to at all
        $this->assertRefused(EventNotLinkable::class, fn () => $this->link(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_06]));
    }

    public function testASeriesIsASeriesPickWithItsEditionDerived(): void
    {
        $result = $this->link(PlayerFixture::PLAYER_REGULAR, null, OrganizationFixture::SERIES_LANTERN_NIGHTS, [PuzzleSolvingTimeFixture::TIME_06]);

        self::assertSame(1, $result->linked);
        $row = $this->time(PuzzleSolvingTimeFixture::TIME_06);
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $row['competition_series_id']);
        // The matching rule decided (SeriesEditionResolver): an edition with how it matched, or series level
        self::assertSame($row['competition_id'] === null, $row['series_edition_match'] === null);
    }

    /**
     * @param list<string> $timeIds
     */
    private function link(string $actor, null|string $competitionId, null|string $seriesId, array $timeIds): LinkedSolvingTimes
    {
        $result = $this->bus->dispatch(new LinkSolvingTimesToEvent($actor, $competitionId, $seriesId, $timeIds))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(LinkedSolvingTimes::class, $result);

        return $result;
    }

    /**
     * @return array{applied: int, dropped: int}
     */
    private function apply(): array
    {
        /** @var array{applied: int, dropped: int} $result */
        $result = $this->bus->dispatch(new ApplyWaitingEventLinks())->last(HandledStamp::class)?->getResult();

        return $result;
    }

    /**
     * @return array{competition_id: null|string, competition_series_id: null|string, competition_round_id: null|string, series_edition_match: null|string}
     */
    private function time(string $id): array
    {
        /** @var array{competition_id: null|string, competition_series_id: null|string, competition_round_id: null|string, series_edition_match: null|string} $row */
        $row = $this->connection->fetchAssociative('SELECT competition_id, competition_series_id, competition_round_id, series_edition_match FROM puzzle_solving_time WHERE id = :id', ['id' => $id]);

        return $row;
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertRefused(string $class, callable $call): void
    {
        try {
            $call();
            self::fail('Expected ' . $class);
        } catch (\Throwable $failure) {
            self::assertNotNull(EventWizardRefusal::find($failure, $class), (string) $failure);
        }

        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param array<string, string> $parameters
     */
    private function number(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);

        return is_numeric($value) ? (int) $value : 0;
    }
}
