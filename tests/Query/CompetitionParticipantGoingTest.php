<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Query\CompetitionParticipantGoing;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Query\GetEventsWithSellersGoing;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Results\ConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Results\SeriesEdition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One "is going" rule (CompetitionParticipantGoing): a waitlisted registration is nobody's "going" - not on the
 * marketplace at events, not in the attendance block, not in the public participant list, not in a series' edition
 * counts. Rows of events without managed registration have no status: for them nothing changed.
 */
final class CompetitionParticipantGoingTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private int $listingsBefore = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheRule(): void
    {
        self::assertSame(
            "cp.deleted_at IS NULL AND cp.registration_status IS DISTINCT FROM 'waitlisted'",
            CompetitionParticipantGoing::sql('cp'),
        );
    }

    public function testWaitlistedPlayerIsNotGoingUntilPromoted(): void
    {
        // WJPC 2024 is a marketplace event with people going already - a limit of 1 makes the next one wait
        $this->listingsBefore = $this->wjpcListingsOfSellersGoing();
        $this->manage(CompetitionFixture::COMPETITION_WJPC_2024, capacity: 1);
        $this->join(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_WITH_STRIPE);

        $participantId = $this->participantIdOf(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(RegistrationStatus::Waitlisted->value, $this->statusOf($participantId));

        $this->assertGoing(false, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->messageBus->dispatch(new PromoteParticipantFromWaitlist(CompetitionFixture::COMPETITION_WJPC_2024, $participantId));

        $this->assertGoing(true, PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testSeriesEditionCountsOnlyThePeopleGoing(): void
    {
        $before = $this->editionParticipantCount();

        $this->manage(CompetitionSeriesFixture::EDITION_OFFLINE_1, capacity: $before);
        $this->join(CompetitionSeriesFixture::EDITION_OFFLINE_1, PlayerFixture::PLAYER_ADMIN);

        self::assertSame(
            RegistrationStatus::Waitlisted->value,
            $this->statusOf($this->participantIdOf(CompetitionSeriesFixture::EDITION_OFFLINE_1, PlayerFixture::PLAYER_ADMIN)),
        );
        self::assertSame($before, $this->editionParticipantCount());
    }

    /**
     * Without managed registration every row has no status - the readers return what "not deleted" returned.
     */
    public function testEventsWithoutManagedRegistrationReadAsBefore(): void
    {
        $connected = self::getContainer()->get(GetCompetitionParticipants::class)->getConnectedParticipants(CompetitionFixture::COMPETITION_WJPC_2024);
        $notDeleted = self::toInt($this->database->fetchOne(
            'SELECT COUNT(DISTINCT cp.id) FROM competition_participant cp WHERE cp.competition_id = :id AND cp.player_id IS NOT NULL AND cp.deleted_at IS NULL',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        ));
        // The admin sees everybody (no blocks of their own)
        self::assertCount($notDeleted, $connected);

        // .claude/fixtures.md "Marketplace at events": C = [SWAP_FAIR, WJPC_2024]
        $events = array_map(
            static fn (MarketplaceEvent $event): string => $event->competitionId,
            self::getContainer()->get(GetMarketplaceEvents::class)->forPlayer(PlayerFixture::PLAYER_REGULAR),
        );
        self::assertEqualsCanonicalizing([MarketplaceEventFixture::COMPETITION_SWAP_FAIR, CompetitionFixture::COMPETITION_WJPC_2024], $events);
    }

    private function assertGoing(bool $going, string $playerId): void
    {
        $competitionId = CompetitionFixture::COMPETITION_WJPC_2024;

        self::assertSame($going, self::getContainer()->get(GetMarketplaceEvents::class)->isPlayerGoing($competitionId, $playerId));

        $attendance = self::getContainer()->get(GetEventAttendance::class)->forEvent(
            self::getContainer()->get(GetCompetitionEvents::class)->byId($competitionId),
            $playerId,
            true,
        );
        self::assertSame($going, $attendance->isGoing);

        $listed = array_map(
            static fn (ConnectedCompetitionParticipant $participant): string => $participant->playerId,
            self::getContainer()->get(GetCompetitionParticipants::class)->getConnectedParticipants($competitionId),
        );
        self::assertSame($going, in_array($playerId, $listed, true));

        // The marketplace's "sellers going": PLAYER_WITH_STRIPE's published listings count only while they are going
        self::assertSame($going, $this->wjpcListingsOfSellersGoing() > $this->listingsBefore);
    }

    private function wjpcListingsOfSellersGoing(): int
    {
        foreach (self::getContainer()->get(GetEventsWithSellersGoing::class)->load() as $event) {
            if ($event->event->competitionId === CompetitionFixture::COMPETITION_WJPC_2024) {
                return $event->bringingCount + $event->askCount;
            }
        }

        return 0;
    }

    private function editionParticipantCount(): int
    {
        $editions = self::getContainer()->get(GetCompetitionSeries::class)->upcomingEditions(CompetitionSeriesFixture::SERIES_OFFLINE);
        $edition = array_values(array_filter(
            $editions,
            static fn (SeriesEdition $edition): bool => $edition->competitionId === CompetitionSeriesFixture::EDITION_OFFLINE_1,
        ));
        self::assertCount(1, $edition);

        return $edition[0]->participantCount;
    }

    private function manage(string $competitionId, int $capacity): void
    {
        $this->messageBus->dispatch(new ChangeCompetitionRegistrationSettings(
            competitionId: $competitionId,
            registrationManaged: true,
            capacity: $capacity,
            registrationOpensAt: null,
            registrationClosesAt: null,
            timezone: 'Europe/Prague',
            entryFeeText: null,
            paymentInstructions: null,
        ));
    }

    private function join(string $competitionId, string $playerId): void
    {
        $this->messageBus->dispatch(new JoinCompetition(competitionId: $competitionId, playerId: $playerId));
    }

    private function participantIdOf(string $competitionId, string $playerId): string
    {
        $id = $this->database->fetchOne(
            'SELECT id FROM competition_participant WHERE competition_id = :competitionId AND player_id = :playerId AND deleted_at IS NULL',
            ['competitionId' => $competitionId, 'playerId' => $playerId],
        );
        self::assertIsString($id);

        return $id;
    }

    private function statusOf(string $participantId): null|string
    {
        /** @var false|null|string $status */
        $status = $this->database->fetchOne('SELECT registration_status FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return $status === false ? null : $status;
    }

    private static function toInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
