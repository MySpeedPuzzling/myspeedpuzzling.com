<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/competitions-management/README.md "Moving an event's date": EditCompetition `moveRoundsWithDate` moves
 * every round's start and the registration window by the days the first day moved, each keeping its time of day in its
 * own zone - only while no round has begun (stopwatch, official result, secret puzzle). Works on a fresh copy of the
 * Results Cup (5 rounds, nothing people did).
 */
final class EditCompetitionMoveRoundsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTickedTheRoundsAndTheRegistrationWindowMoveWithTheFirstDay(): void
    {
        $eventId = $this->copy();
        $this->database->executeStatement(
            "UPDATE competition SET registration_opens_at = '2030-01-01 08:00:00', registration_closes_at = '2030-01-20 20:00:00' WHERE id = :id",
            ['id' => $eventId],
        );
        $before = $this->starts($eventId);
        $dateFrom = $this->competition($eventId)->dateFrom;
        self::assertNotNull($dateFrom);

        $this->messageBus->dispatch($this->edit($eventId, $dateFrom->modify('+14 days'), move: true));

        self::assertSame(
            array_map(static fn (DateTimeImmutable $start): string => $start->modify('+14 days')->format('Y-m-d H:i'), $before),
            array_map(static fn (DateTimeImmutable $start): string => $start->format('Y-m-d H:i'), $this->starts($eventId)),
        );
        $event = $this->competition($eventId);
        self::assertSame('2030-01-15 08:00', $event->registrationOpensAt?->format('Y-m-d H:i'));
        self::assertSame('2030-02-03 20:00', $event->registrationClosesAt?->format('Y-m-d H:i'));
    }

    public function testNotTickedTheRoundsStay(): void
    {
        $eventId = $this->copy();
        $before = $this->starts($eventId);
        $dateFrom = $this->competition($eventId)->dateFrom;
        self::assertNotNull($dateFrom);

        $this->messageBus->dispatch($this->edit($eventId, $dateFrom->modify('+14 days'), move: false));

        self::assertEquals($before, $this->starts($eventId));
        self::assertSame($dateFrom->modify('+14 days')->format('Y-m-d'), $this->competition($eventId)->dateFrom?->format('Y-m-d'));
    }

    public function testARoundWithAStopwatchKeepsEveryRoundWhereItIs(): void
    {
        $eventId = $this->copy();
        // A scheduled start (a countdown) counts too - its moment is the organiser's own
        $this->database->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'scheduled', stopwatch_started_at = starts_at WHERE id = (SELECT id FROM competition_round WHERE competition_id = :id ORDER BY starts_at LIMIT 1)",
            ['id' => $eventId],
        );
        $before = $this->starts($eventId);
        $dateFrom = $this->competition($eventId)->dateFrom;
        self::assertNotNull($dateFrom);

        $this->messageBus->dispatch($this->edit($eventId, $dateFrom->modify('+7 days'), move: true));

        self::assertEquals($before, $this->starts($eventId));
        // The event's own date changes all the same
        self::assertSame($dateFrom->modify('+7 days')->format('Y-m-d'), $this->competition($eventId)->dateFrom?->format('Y-m-d'));
    }

    public function testAnEventWithOfficialResultsKeepsItsRounds(): void
    {
        $eventId = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $before = $this->starts($eventId);
        $dateFrom = $this->competition($eventId)->dateFrom;
        self::assertNotNull($dateFrom);

        $this->messageBus->dispatch($this->edit($eventId, $dateFrom->modify('+7 days'), move: true));

        self::assertEquals($before, $this->starts($eventId));
    }

    public function testARoundAtTenInPragueStaysAtTenAcrossTheDaylightSavingChange(): void
    {
        $eventId = $this->copy();
        $prague = new DateTimeZone('Europe/Prague');
        $this->database->executeStatement("UPDATE competition SET date_from = '2027-03-20 00:00:00', date_to = '2027-03-20 00:00:00' WHERE id = :id", ['id' => $eventId]);
        // Every round at 10:00 Prague winter time (09:00 UTC) on 20 March 2027 - summer time starts on 28 March
        $this->database->executeStatement(
            "UPDATE competition_round SET starts_at = '2027-03-20 09:00:00', timezone = 'Europe/Prague' WHERE competition_id = :id",
            ['id' => $eventId],
        );

        $this->messageBus->dispatch($this->edit($eventId, new DateTimeImmutable('2027-04-03'), move: true, dateTo: new DateTimeImmutable('2027-04-03')));

        foreach ($this->starts($eventId) as $start) {
            self::assertSame('2027-04-03 10:00', $start->setTimezone($prague)->format('Y-m-d H:i'));
        }
    }

    /**
     * A fresh draft copy of the Results Cup: its 5 rounds, nothing people did
     */
    private function copy(): string
    {
        $copyId = Uuid::uuid7();
        $this->messageBus->dispatch(new DuplicateCompetition($copyId, OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_WITH_STRIPE));

        return $copyId->toString();
    }

    private function edit(string $eventId, DateTimeImmutable $dateFrom, bool $move, null|DateTimeImmutable $dateTo = null): EditCompetition
    {
        $competition = $this->competition($eventId);

        return new EditCompetition(
            competitionId: $eventId,
            name: $competition->name,
            shortcut: $competition->shortcut,
            description: $competition->description,
            link: $competition->link,
            registrationLink: $competition->registrationLink,
            resultsLink: $competition->resultsLink,
            location: $competition->location,
            locationCountryCode: $competition->locationCountryCode,
            dateFrom: $dateFrom,
            dateTo: $dateTo ?? $dateFrom,
            isOnline: $competition->isOnline,
            logo: null,
            maintainerIds: [],
            eligibility: null,
            eventTypes: null,
            moveRoundsWithDate: $move,
        );
    }

    private function competition(string $id): Competition
    {
        $this->entityManager->clear();
        $competition = $this->entityManager->find(Competition::class, $id);
        self::assertNotNull($competition);

        return $competition;
    }

    /**
     * @return list<DateTimeImmutable> the rounds' starts in UTC, by start
     */
    private function starts(string $eventId): array
    {
        $this->entityManager->clear();
        /** @var list<CompetitionRound> $rounds */
        $rounds = $this->entityManager->getRepository(CompetitionRound::class)->findBy(['competition' => $eventId], ['startsAt' => 'ASC', 'name' => 'ASC']);

        return array_map(
            static fn (CompetitionRound $round): DateTimeImmutable => $round->startsAt->setTimezone(new DateTimeZone('UTC')),
            $rounds,
        );
    }
}
