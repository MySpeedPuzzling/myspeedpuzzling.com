<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidRegistrationSettings;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Exceptions\RegistrationNotManaged;
use SpeedPuzzling\Web\Exceptions\RegistrationNotOpen;
use SpeedPuzzling\Web\Message\AddCompetitionParticipant;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Message\RestoreCompetitionParticipant;
use SpeedPuzzling\Web\Message\UndoParticipantCheckIn;
use SpeedPuzzling\Web\Message\UnmarkParticipantPaid;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Managed registration (docs/features/competitions-management/registration.md): capacity, waitlist, window,
 * visibility and the organiser's actions - through the real bus, so the participants lock and the transaction run too.
 */
final class CompetitionRegistrationTest extends KernelTestCase
{
    private const string EVENT = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;

    private MessageBusInterface $messageBus;
    private CompetitionParticipantRepository $participantRepository;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->participantRepository = self::getContainer()->get(CompetitionParticipantRepository::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testRegistrationUnderTheCapacityIsReservedAndConfirmedByEmail(): void
    {
        $this->manage(capacity: 2, entryFee: '10 EUR', paymentInstructions: 'Bank 123/0100');

        $this->join(PlayerFixture::PLAYER_REGULAR);

        $participant = $this->rowOf(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(RegistrationStatus::Reserved, $participant->registrationStatus);
        self::assertNotNull($participant->registeredAt);

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $email->getTo()[0]->getAddress());
        self::assertSame("You're registered for Czech National Championship 2024", $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Bank 123/0100', $html);
        self::assertStringContainsString('does not process payments', $html);
    }

    public function testFullEventPutsNewRegistrationsOnTheWaitlistFirstComeFirstServed(): void
    {
        $this->manage(capacity: 1);

        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $this->join(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(RegistrationStatus::Reserved, $this->rowOf(PlayerFixture::PLAYER_REGULAR)->registrationStatus);
        self::assertSame(RegistrationStatus::Waitlisted, $this->rowOf(PlayerFixture::PLAYER_ADMIN)->registrationStatus);
        self::assertSame(RegistrationStatus::Waitlisted, $this->rowOf(PlayerFixture::PLAYER_WITH_FAVORITES)->registrationStatus);

        self::assertSame(1, $this->registrationOf(PlayerFixture::PLAYER_ADMIN)->playerWaitlistPosition);
        self::assertSame(2, $this->registrationOf(PlayerFixture::PLAYER_WITH_FAVORITES)->playerWaitlistPosition);

        $emails = self::getMailerMessages();
        self::assertCount(3, $emails);
        $last = $emails[2];
        self::assertInstanceOf(TemplatedEmail::class, $last);
        self::assertSame("You're on the waitlist for Czech National Championship 2024", $last->getSubject());
        self::assertStringContainsString('#2', (string) $last->getHtmlBody());
    }

    public function testClosedWindowRefusesANewRegistrationBeforeAnythingChanges(): void
    {
        // PLAYER_REGULAR is on the organiser's list of WJPC 2024 (PARTICIPANT_CONNECTED)
        $this->manage(competitionId: CompetitionFixture::COMPETITION_WJPC_2024, closesAt: $this->now()->modify('-1 hour'));

        $this->assertRefused(RegistrationAvailability::Closed, CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_REGULAR);

        // "Not on the list" would let go of the organiser's row first - the refusal came before it
        $connected = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_CONNECTED);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $connected->player?->id->toString());
        self::assertQueuedEmailCount(0);
    }

    public function testRegistrationThatHasNotOpenedYetIsRefused(): void
    {
        $this->manage(opensAt: $this->now()->modify('+1 day'));

        $this->assertRefused(RegistrationAvailability::NotYetOpen, self::EVENT, PlayerFixture::PLAYER_REGULAR);
        self::assertSame(0, $this->rowCount(self::EVENT));
    }

    public function testEventThatIsNotPubliclyVisibleTakesNoRegistrations(): void
    {
        $this->manage(competitionId: CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertRefused(RegistrationAvailability::NotPublic, CompetitionFixture::COMPETITION_UNAPPROVED, PlayerFixture::PLAYER_ADMIN);
        self::assertSame(0, $this->rowCount(CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertQueuedEmailCount(0);
    }

    public function testPickingYourNameFromTheOrganisersListWorksAfterRegistrationClosed(): void
    {
        $this->manage(competitionId: CompetitionFixture::COMPETITION_WJPC_2024, capacity: 1, closesAt: $this->now()->modify('-1 hour'));

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ));

        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $participant->player?->id->toString());
        // The organiser holds that spot - no registration of its own, no e-mail
        self::assertNull($participant->registrationStatus);
        self::assertQueuedEmailCount(0);
    }

    public function testCancellingFreesTheSpotAndRegisteringAgainStartsFresh(): void
    {
        $this->manage(capacity: 1);

        $this->join(PlayerFixture::PLAYER_REGULAR);
        $first = $this->rowOf(PlayerFixture::PLAYER_REGULAR);
        $this->messageBus->dispatch(new MarkParticipantPaid(self::EVENT, $first->id->toString()));

        $this->messageBus->dispatch(new LeaveCompetition(self::EVENT, PlayerFixture::PLAYER_REGULAR));
        $this->join(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(RegistrationStatus::Reserved, $this->rowOf(PlayerFixture::PLAYER_ADMIN)->registrationStatus);

        $this->join(PlayerFixture::PLAYER_REGULAR);
        $again = $this->rowOf(PlayerFixture::PLAYER_REGULAR);
        // The same row, restored - at the end of the queue, not paid any more (when it was paid stays on record)
        self::assertTrue($again->id->equals($first->id));
        self::assertSame(RegistrationStatus::Waitlisted, $again->registrationStatus);
        self::assertNotNull($again->paidAt);
    }

    public function testMarkPaidOnceAndOnTheWaitlistOnlyTogetherWithAPromotion(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $reserved = $this->rowOf(PlayerFixture::PLAYER_REGULAR)->id->toString();
        $waitlisted = $this->rowOf(PlayerFixture::PLAYER_ADMIN)->id->toString();
        $emailsBefore = count(self::getMailerMessages());

        $this->messageBus->dispatch(new MarkParticipantPaid(self::EVENT, $reserved));
        $this->messageBus->dispatch(new MarkParticipantPaid(self::EVENT, $reserved));

        self::assertSame(RegistrationStatus::Paid, $this->participantRepository->get($reserved)->registrationStatus);
        self::assertNotNull($this->participantRepository->get($reserved)->paidAt);
        self::assertCount($emailsBefore + 1, self::getMailerMessages(), 'A second "paid" sends no second e-mail');

        try {
            $this->messageBus->dispatch(new MarkParticipantPaid(self::EVENT, $waitlisted));
            self::fail('A waitlisted participant is never marked paid without a promotion');
        } catch (ParticipantIsWaitlisted) {
        }
        self::assertSame(RegistrationStatus::Waitlisted, $this->participantRepository->get($waitlisted)->registrationStatus);

        $this->messageBus->dispatch(new MarkParticipantPaid(self::EVENT, $waitlisted, promoteFromWaitlist: true));
        self::assertSame(RegistrationStatus::Paid, $this->participantRepository->get($waitlisted)->registrationStatus);
    }

    public function testPromotionOnlyFromTheWaitlistAndOnlyOnce(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $reserved = $this->rowOf(PlayerFixture::PLAYER_REGULAR)->id->toString();
        $waitlisted = $this->rowOf(PlayerFixture::PLAYER_ADMIN)->id->toString();
        $this->messageBus->dispatch(new MarkParticipantPaid(self::EVENT, $reserved));
        $emailsBefore = count(self::getMailerMessages());

        $this->messageBus->dispatch(new PromoteParticipantFromWaitlist(self::EVENT, $waitlisted));
        $this->messageBus->dispatch(new PromoteParticipantFromWaitlist(self::EVENT, $waitlisted));
        // A paid participant is not "promoted" back to reserved
        $this->messageBus->dispatch(new PromoteParticipantFromWaitlist(self::EVENT, $reserved));

        self::assertSame(RegistrationStatus::Reserved, $this->participantRepository->get($waitlisted)->registrationStatus);
        self::assertSame(RegistrationStatus::Paid, $this->participantRepository->get($reserved)->registrationStatus);

        $emails = self::getMailerMessages();
        self::assertCount($emailsBefore + 1, $emails);
        $promoted = end($emails);
        self::assertInstanceOf(TemplatedEmail::class, $promoted);
        self::assertSame('A spot opened up for you at Czech National Championship 2024', $promoted->getSubject());
    }

    public function testUnmarkPaidOnlyTakesBackAPayment(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $reserved = $this->rowOf(PlayerFixture::PLAYER_REGULAR)->id->toString();
        $waitlisted = $this->rowOf(PlayerFixture::PLAYER_ADMIN)->id->toString();

        $this->messageBus->dispatch(new MarkParticipantPaid(self::EVENT, $reserved));
        $this->messageBus->dispatch(new UnmarkParticipantPaid(self::EVENT, $reserved));
        // A waitlisted participant never jumps the queue this way
        $this->messageBus->dispatch(new UnmarkParticipantPaid(self::EVENT, $waitlisted));

        self::assertSame(RegistrationStatus::Reserved, $this->participantRepository->get($reserved)->registrationStatus);
        self::assertNull($this->participantRepository->get($reserved)->paidAt);
        self::assertSame(RegistrationStatus::Waitlisted, $this->participantRepository->get($waitlisted)->registrationStatus);
    }

    public function testCheckInAndUndoForEverybodyHoldingASpot(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $reserved = $this->rowOf(PlayerFixture::PLAYER_REGULAR)->id->toString();
        $waitlisted = $this->rowOf(PlayerFixture::PLAYER_ADMIN)->id->toString();

        $this->messageBus->dispatch(new CheckInParticipant(self::EVENT, $reserved));
        self::assertNotNull($this->participantRepository->get($reserved)->checkedInAt);

        $this->messageBus->dispatch(new UndoParticipantCheckIn(self::EVENT, $reserved));
        self::assertNull($this->participantRepository->get($reserved)->checkedInAt);

        $this->expectException(ParticipantIsWaitlisted::class);
        $this->messageBus->dispatch(new CheckInParticipant(self::EVENT, $waitlisted));
    }

    /**
     * An organiser of one event is authorised for that event only - any signed-in player can create an event and
     * maintain it, so an id of another event's participant must never reach that participant (or e-mail them).
     *
     * @param callable(string, string): object $message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideOrganiserActions')]
    public function testOrganiserActionNeverReachesAParticipantOfAnotherEvent(callable $message): void
    {
        $this->manage(capacity: 5);
        $this->manage(competitionId: CompetitionFixture::COMPETITION_WJPC_2024, capacity: 5);

        try {
            $this->messageBus->dispatch($message(self::EVENT, CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
            self::fail('A participant of another event must not be found');
        } catch (CompetitionParticipantNotFound) {
        }

        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_CONNECTED);
        self::assertNull($participant->registrationStatus);
        self::assertNull($participant->checkedInAt);
        self::assertQueuedEmailCount(0);
    }

    /**
     * @return iterable<string, array{callable(string, string): object}>
     */
    public static function provideOrganiserActions(): iterable
    {
        yield 'mark paid' => [static fn (string $competitionId, string $participantId): object => new MarkParticipantPaid($competitionId, $participantId)];
        yield 'promote and mark paid' => [static fn (string $competitionId, string $participantId): object => new MarkParticipantPaid($competitionId, $participantId, promoteFromWaitlist: true)];
        yield 'unmark paid' => [static fn (string $competitionId, string $participantId): object => new UnmarkParticipantPaid($competitionId, $participantId)];
        yield 'promote' => [static fn (string $competitionId, string $participantId): object => new PromoteParticipantFromWaitlist($competitionId, $participantId)];
        yield 'check in' => [static fn (string $competitionId, string $participantId): object => new CheckInParticipant($competitionId, $participantId)];
        yield 'undo check-in' => [static fn (string $competitionId, string $participantId): object => new UndoParticipantCheckIn($competitionId, $participantId)];
    }

    public function testRegistrationActionsNeedManagedRegistration(): void
    {
        $this->expectException(RegistrationNotManaged::class);

        $this->messageBus->dispatch(new MarkParticipantPaid(
            CompetitionFixture::COMPETITION_WJPC_2024,
            CompetitionParticipantFixture::PARTICIPANT_CONNECTED,
        ));
    }

    public function testOrganiserAddedParticipantHoldsASpotEvenAboveTheCapacity(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);

        $this->messageBus->dispatch(new AddCompetitionParticipant(self::EVENT, 'Listed By Hand', 'cz', null, null));

        $added = $this->database->fetchOne(
            'SELECT registration_status FROM competition_participant WHERE competition_id = :id AND name = :name',
            ['id' => self::EVENT, 'name' => 'Listed By Hand'],
        );
        self::assertSame(RegistrationStatus::Reserved->value, $added);
    }

    public function testSwitchingManagementOffMakesTheWaitlistGoingAndTellsThem(): void
    {
        $this->manage(capacity: 1, entryFee: '10 EUR', paymentInstructions: 'Bank 123/0100');
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(RegistrationStatus::Waitlisted, $this->rowOf(PlayerFixture::PLAYER_ADMIN)->registrationStatus);
        $before = count(self::getMailerMessages());

        $this->manage(managed: false, capacity: 1, entryFee: '10 EUR', paymentInstructions: 'Bank 123/0100');

        self::assertSame(RegistrationStatus::Reserved, $this->rowOf(PlayerFixture::PLAYER_ADMIN)->registrationStatus);
        self::assertSame(0, self::toInt($this->database->fetchOne(
            "SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id AND registration_status = 'waitlisted'",
            ['id' => self::EVENT],
        )));

        // The waitlist e-mail promised one when a spot opens up - kept, and nobody is asked to pay for an event that no
        // longer manages registration
        $emails = array_slice(self::getMailerMessages(), $before);
        self::assertCount(1, $emails);
        self::assertInstanceOf(TemplatedEmail::class, $emails[0]);
        self::assertSame(PlayerFixture::PLAYER_ADMIN_EMAIL, $emails[0]->getTo()[0]->getAddress());
        self::assertSame('A spot opened up for you at Czech National Championship 2024', $emails[0]->getSubject());
        self::assertStringNotContainsString('Bank 123/0100', (string) $emails[0]->getHtmlBody());
    }

    /**
     * Review 2, A-F1: B leaves the waitlist (the row is removed, its status stays), the organiser switches management
     * off, B clicks "I'm going" - B must be going, not stuck on a waitlist of an event that has none.
     */
    public function testWhoLeftTheWaitlistIsGoingWhenJoiningAgainAfterManagementWasSwitchedOff(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $this->messageBus->dispatch(new LeaveCompetition(self::EVENT, PlayerFixture::PLAYER_ADMIN));
        $before = count(self::getMailerMessages());

        $this->manage(managed: false);

        // Nobody is e-mailed about a waitlist they had left
        self::assertCount($before, self::getMailerMessages());

        $this->join(PlayerFixture::PLAYER_ADMIN);

        $row = $this->rowOf(PlayerFixture::PLAYER_ADMIN);
        self::assertFalse($row->isDeleted());
        self::assertNotSame(RegistrationStatus::Waitlisted, $row->registrationStatus);
        self::assertTrue(self::getContainer()->get(GetEventAttendance::class)->forEvent(
            self::getContainer()->get(GetCompetitionEvents::class)->byId(self::EVENT),
            PlayerFixture::PLAYER_ADMIN,
            true,
        )->isGoing);
    }

    /**
     * Belt and braces for rows that are waitlisted on an event without management anyway (written by SQL, or before the
     * switch-off covered removed rows): coming back - joining again or the organiser's restore - makes them going.
     */
    public function testAWaitlistedRowComingBackOnAnEventWithoutManagementIsGoing(): void
    {
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $rowId = $this->rowOf(PlayerFixture::PLAYER_ADMIN)->id->toString();
        $this->database->executeStatement(
            "UPDATE competition_participant SET registration_status = 'waitlisted', deleted_at = NOW() WHERE id = :id",
            ['id' => $rowId],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->messageBus->dispatch(new RestoreCompetitionParticipant(self::EVENT, $rowId));
        self::assertSame(RegistrationStatus::Reserved, $this->rowOf(PlayerFixture::PLAYER_ADMIN)->registrationStatus);

        $this->database->executeStatement(
            "UPDATE competition_participant SET registration_status = 'waitlisted', deleted_at = NOW() WHERE id = :id",
            ['id' => $rowId],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->join(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(RegistrationStatus::Reserved, $this->rowOf(PlayerFixture::PLAYER_ADMIN)->registrationStatus);
    }

    /**
     * Review 2, A-F5: no closing time set - registration closes at the end of the event's last day in its zone.
     */
    public function testRegistrationWithoutAClosingTimeClosesWhenTheEventIsOver(): void
    {
        $this->manage(capacity: 10, entryFee: '10 EUR', paymentInstructions: 'Bank 123/0100');
        $today = $this->now()->setTimezone(new DateTimeZone('Europe/Prague'))->format('Y-m-d');

        // Today is the event's last day - still open
        $this->setDates($this->now()->modify('-2 days'), new DateTimeImmutable($today));
        self::assertSame(RegistrationAvailability::Open, $this->registrationOf(PlayerFixture::PLAYER_REGULAR)->availability);

        // It was yesterday - closed: no registration, no payment instructions
        $this->setDates($this->now()->modify('-3 days'), new DateTimeImmutable($today)->modify('-1 day'));
        self::assertSame(RegistrationAvailability::Closed, $this->registrationOf(PlayerFixture::PLAYER_REGULAR)->availability);
        $this->assertRefused(RegistrationAvailability::Closed, self::EVENT, PlayerFixture::PLAYER_REGULAR);
        self::assertQueuedEmailCount(0);

        // A one-day event (no date_to) is over after its day too
        $this->setDates(new DateTimeImmutable($today)->modify('-1 day'), null);
        $this->assertRefused(RegistrationAvailability::Closed, self::EVENT, PlayerFixture::PLAYER_REGULAR);
    }

    /**
     * An edition without a country of its own and without a saved zone reads in its series' country zone - on the card
     * like in the export (review 2 nit).
     */
    public function testTheCardOfAnEditionReadsTheSeriesCountryZoneLikeTheExport(): void
    {
        $edition = CompetitionSeriesFixture::EDITION_OFFLINE_1;
        $this->database->executeStatement(
            'UPDATE competition SET registration_managed = true, registration_timezone = NULL, location_country_code = NULL WHERE id = :id',
            ['id' => $edition],
        );
        $this->database->executeStatement(
            "UPDATE competition_series SET location_country_code = 'jp' WHERE id = :id",
            ['id' => CompetitionSeriesFixture::SERIES_OFFLINE],
        );

        $registration = self::getContainer()->get(GetEventAttendance::class)->forEvent(
            self::getContainer()->get(GetCompetitionEvents::class)->byId($edition),
            null,
            true,
        )->registration;

        self::assertNotNull($registration);
        self::assertSame('Asia/Tokyo', $registration->timezone);
    }

    /**
     * Review 2, A-F4: a registration made again after cancelling starts over, but the organiser's record of a payment is
     * kept - the participants page shows when it was paid, "Mark paid" confirms it again.
     */
    public function testRegisteringAgainKeepsTheRecordOfAnEarlierPayment(): void
    {
        $this->manage(capacity: 5);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $first = $this->rowOf(PlayerFixture::PLAYER_REGULAR);
        $this->messageBus->dispatch(new MarkParticipantPaid(self::EVENT, $first->id->toString()));
        $paidAt = $this->rowOf(PlayerFixture::PLAYER_REGULAR)->paidAt;
        self::assertNotNull($paidAt);

        $this->messageBus->dispatch(new LeaveCompetition(self::EVENT, PlayerFixture::PLAYER_REGULAR));
        self::assertEquals($paidAt, $this->participantRepository->get($first->id->toString())->paidAt, 'Cancelling keeps the record');

        $this->join(PlayerFixture::PLAYER_REGULAR);
        $again = $this->rowOf(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(RegistrationStatus::Reserved, $again->registrationStatus);
        self::assertEquals($paidAt, $again->paidAt);
    }

    public function testSettingsNeverTouchTheExternalRegistrationLink(): void
    {
        $this->manage(competitionId: CompetitionFixture::COMPETITION_WJPC_2024);

        $link = $this->database->fetchOne('SELECT registration_link FROM competition WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);
        self::assertSame('https://wjpc2024.com/register', $link);

        $event = self::getContainer()->get(GetCompetitionEvents::class)->byId(CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNull($event->registrationLink, 'Hidden while registration is managed here');

        $this->manage(competitionId: CompetitionFixture::COMPETITION_WJPC_2024, managed: false);

        $event = self::getContainer()->get(GetCompetitionEvents::class)->byId(CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNotNull($event->registrationLink);
        self::assertStringStartsWith('https://wjpc2024.com/register', $event->registrationLink);
    }

    public function testWindowClosingBeforeItOpensIsRefused(): void
    {
        $this->expectException(InvalidRegistrationSettings::class);

        $this->manage(opensAt: new DateTimeImmutable('2030-01-02 10:00'), closesAt: new DateTimeImmutable('2030-01-01 10:00'));
    }

    public function testJoiningAnEventWithoutManagedRegistrationIsUnchanged(): void
    {
        $this->join(PlayerFixture::PLAYER_REGULAR);

        $participant = $this->rowOf(PlayerFixture::PLAYER_REGULAR);
        self::assertNull($participant->registrationStatus);
        self::assertNull($participant->registeredAt);
        self::assertQueuedEmailCount(0);
        self::assertNull(self::getContainer()->get(GetEventAttendance::class)->forEvent(
            self::getContainer()->get(GetCompetitionEvents::class)->byId(self::EVENT),
            PlayerFixture::PLAYER_REGULAR,
            true,
        )->registration);
    }

    private function manage(
        string $competitionId = self::EVENT,
        bool $managed = true,
        null|int $capacity = null,
        null|DateTimeImmutable $opensAt = null,
        null|DateTimeImmutable $closesAt = null,
        null|string $entryFee = null,
        null|string $paymentInstructions = null,
    ): void {
        $this->messageBus->dispatch(new ChangeCompetitionRegistrationSettings(
            competitionId: $competitionId,
            registrationManaged: $managed,
            capacity: $capacity,
            registrationOpensAt: $opensAt,
            registrationClosesAt: $closesAt,
            timezone: 'Europe/Prague',
            entryFeeText: $entryFee,
            paymentInstructions: $paymentInstructions,
        ));
    }

    private function setDates(DateTimeImmutable $dateFrom, null|DateTimeImmutable $dateTo): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET date_from = :dateFrom, date_to = :dateTo WHERE id = :id',
            ['dateFrom' => $dateFrom->format('Y-m-d 00:00:00'), 'dateTo' => $dateTo?->format('Y-m-d 00:00:00'), 'id' => self::EVENT],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function join(string $playerId, string $competitionId = self::EVENT): void
    {
        $this->messageBus->dispatch(new JoinCompetition(competitionId: $competitionId, playerId: $playerId));
    }

    private function assertRefused(RegistrationAvailability $availability, string $competitionId, string $playerId): void
    {
        try {
            $this->join($playerId, $competitionId);
            self::fail('The registration must be refused');
        } catch (HandlerFailedException $e) {
            $refusal = $e->getPrevious();
            self::assertInstanceOf(RegistrationNotOpen::class, $refusal);
            self::assertSame($availability, $refusal->availability);
        }
    }

    private function rowOf(string $playerId, string $competitionId = self::EVENT): CompetitionParticipant
    {
        /** @var false|string $id */
        $id = $this->database->fetchOne(
            'SELECT id FROM competition_participant WHERE competition_id = :competitionId AND player_id = :playerId',
            ['competitionId' => $competitionId, 'playerId' => $playerId],
        );
        self::assertIsString($id);

        $participant = $this->participantRepository->get($id);
        self::getContainer()->get(EntityManagerInterface::class)->refresh($participant);

        return $participant;
    }

    private function rowCount(string $competitionId): int
    {
        return self::toInt($this->database->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id',
            ['id' => $competitionId],
        ));
    }

    private function registrationOf(string $playerId): \SpeedPuzzling\Web\Results\EventRegistration
    {
        $registration = self::getContainer()->get(GetEventAttendance::class)->forEvent(
            self::getContainer()->get(GetCompetitionEvents::class)->byId(self::EVENT),
            $playerId,
            true,
        )->registration;
        self::assertNotNull($registration);

        return $registration;
    }

    private function now(): DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now();
    }

    private static function toInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
