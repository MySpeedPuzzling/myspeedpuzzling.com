<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\RegistrationNotOpen;
use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Message\UndoParticipantCheckIn;
use SpeedPuzzling\Web\Message\UnmarkParticipantPaid;
use SpeedPuzzling\Web\Query\GetCompetitionRegistrationOverview;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class CompetitionRegistrationTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionParticipantRepository $participantRepository;
    private GetCompetitionRegistrationOverview $registrationOverview;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->participantRepository = self::getContainer()->get(CompetitionParticipantRepository::class);
        $this->registrationOverview = self::getContainer()->get(GetCompetitionRegistrationOverview::class);
    }

    public function testSelfJoinUnderCapacityIsReserved(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $registration = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotNull($registration);
        self::assertSame(RegistrationStatus::Reserved, $registration['status']);

        $participant = $this->participantRepository->get($registration['participantId']);
        self::assertNotNull($participant->registeredAt);
    }

    public function testJoinOverCapacityIsWaitlisted(): void
    {
        // Capacity is 2
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        $registration = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        self::assertNotNull($registration);
        self::assertSame(RegistrationStatus::Waitlisted, $registration['status']);
        self::assertSame(1, $registration['waitlistPosition']);
    }

    public function testJoinAfterRegistrationClosedThrows(): void
    {
        $this->expectException(HandlerFailedException::class);

        try {
            $this->messageBus->dispatch(new JoinCompetition(
                competitionId: CompetitionFixture::COMPETITION_MANAGED_CLOSED,
                playerId: PlayerFixture::PLAYER_REGULAR,
            ));
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(RegistrationNotOpen::class, $e->getPrevious());

            throw $e;
        }
    }

    public function testMarkPaidAndUnmarkPaid(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $registration = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            PlayerFixture::PLAYER_REGULAR,
        );
        self::assertNotNull($registration);
        $participantId = $registration['participantId'];

        $this->messageBus->dispatch(new MarkParticipantPaid($participantId));

        $participant = $this->participantRepository->get($participantId);
        self::assertSame(RegistrationStatus::Paid, $participant->registrationStatus);
        self::assertNotNull($participant->paidAt);

        $this->messageBus->dispatch(new UnmarkParticipantPaid($participantId));

        $participant = $this->participantRepository->get($participantId);
        self::assertSame(RegistrationStatus::Reserved, $participant->registrationStatus);
        self::assertNull($participant->paidAt);
    }

    public function testPromoteFromWaitlist(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        $registration = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );
        self::assertNotNull($registration);

        $this->messageBus->dispatch(new PromoteParticipantFromWaitlist($registration['participantId']));

        $participant = $this->participantRepository->get($registration['participantId']);
        self::assertSame(RegistrationStatus::Reserved, $participant->registrationStatus);
    }

    public function testCancellationFreesSpotForNextRegistration(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));

        // Cancel one registration — capacity 2, so a spot frees up
        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        $registration = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        self::assertNotNull($registration);
        self::assertSame(RegistrationStatus::Reserved, $registration['status']);
    }

    public function testRejoinAfterCancellationStartsFreshRegistration(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $registration = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            PlayerFixture::PLAYER_REGULAR,
        );
        self::assertNotNull($registration);

        $this->messageBus->dispatch(new MarkParticipantPaid($registration['participantId']));

        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $rejoined = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotNull($rejoined);
        // Fresh registration is reserved again, not paid
        self::assertSame(RegistrationStatus::Reserved, $rejoined['status']);
    }

    public function testCheckInAndUndo(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $registration = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_MANAGED_REGISTRATION,
            PlayerFixture::PLAYER_REGULAR,
        );
        self::assertNotNull($registration);
        $participantId = $registration['participantId'];

        $this->messageBus->dispatch(new CheckInParticipant($participantId));

        $participant = $this->participantRepository->get($participantId);
        self::assertNotNull($participant->checkedInAt);

        $this->messageBus->dispatch(new UndoParticipantCheckIn($participantId));

        $participant = $this->participantRepository->get($participantId);
        self::assertNull($participant->checkedInAt);
    }

    public function testNonManagedCompetitionJoinKeepsNullStatus(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $registration = $this->registrationOverview->playerRegistration(
            CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotNull($registration);
        self::assertNull($registration['status']);
    }
}
