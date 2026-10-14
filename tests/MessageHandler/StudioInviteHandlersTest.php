<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\StudioInviteNotFound;
use SpeedPuzzling\Web\Exceptions\StudioInviteRefused;
use SpeedPuzzling\Web\Message\AnswerStudioInvite;
use SpeedPuzzling\Web\Message\EndStudioGuest;
use SpeedPuzzling\Web\Message\InviteToStudio;
use SpeedPuzzling\Web\Message\RecordStudioGuestPresence;
use SpeedPuzzling\Web\Results\EndedStudioGuest;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\StudioInviteAnswer;
use SpeedPuzzling\Web\Value\StudioInviteStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The studio's invitations (docs/features/online-competitions/contracts/phase-1b.md §4): InviteToStudio (video on,
 * entered, one open invitation per participant and round), AnswerStudioInvite (join / decline, the same answer again,
 * expiry), EndStudioGuest (on air → ended, a withdrawal, nothing more for one that is over) and LiveKit's word on a guest
 * (RecordStudioGuestPresence) - each checked in the rows.
 */
final class StudioInviteHandlersTest extends KernelTestCase
{
    use LiveVideoTesting;

    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;
    private const string ARTHUR = OnlineRoundsFixture::PARTICIPANT_ANONYMOUS;
    private const string HOST = PlayerFixture::PLAYER_ADMIN;

    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testTheRoundsVideoMustBeOn(): void
    {
        try {
            $this->invite(self::OLIVIA);
            self::fail('The round\'s video is off');
        } catch (StudioInviteRefused $refused) {
            self::assertSame(StudioInviteRefused::VIDEO_UNAVAILABLE, $refused->reason);
            self::assertSame(409, $refused->getStatusCode());
        }

        self::assertSame(0, $this->inviteCount());
    }

    public function testAnInvitationWaitsForTheParticipantsAnswer(): void
    {
        self::turnRoundVideoOn($this->connection);
        $before = new DateTimeImmutable('-1 second');

        $invite = $this->invite(self::OLIVIA);

        self::assertSame(StudioInviteStatus::Invited, $invite->status);
        $row = $this->row($invite->id->toString());
        self::assertSame(self::ROUND, $row['round_id']);
        self::assertSame(self::OLIVIA, $row['participant_id']);
        self::assertSame(self::HOST, $row['invited_by_id']);
        self::assertSame('invited', $row['status']);
        self::assertNull($row['answered_at']);
        self::assertNull($row['on_air_from']);
        self::assertGreaterThanOrEqual($before->format('Y-m-d H:i:s'), $row['invited_at']);
        self::assertEquals($invite->invitedAt->modify('+120 seconds'), $invite->expiresAt());
    }

    public function testOneOpenInvitationPerParticipant(): void
    {
        self::turnRoundVideoOn($this->connection);
        $first = $this->invite(self::OLIVIA);

        try {
            $this->invite(self::OLIVIA);
            self::fail('Olivia has an open invitation');
        } catch (StudioInviteRefused $refused) {
            self::assertSame(StudioInviteRefused::ALREADY_INVITED, $refused->reason);
            self::assertSame($first->id->toString(), $refused->invite?->id->toString());
        }

        // Somebody else may be invited meanwhile
        $this->invite(self::ARTHUR);

        // On air is open too
        $this->answer($first->id->toString(), self::OLIVIA, StudioInviteAnswer::Join);
        $this->expectExceptionObject(StudioInviteRefused::because(StudioInviteRefused::ALREADY_INVITED));
        $this->invite(self::OLIVIA);
    }

    public function testAnInvitationNobodyAnsweredRunsOutAndMakesWayForANewOne(): void
    {
        self::turnRoundVideoOn($this->connection);
        $first = $this->invite(self::OLIVIA);
        $this->age($first->id->toString(), 121);

        $second = $this->invite(self::OLIVIA);

        self::assertNotSame($first->id->toString(), $second->id->toString());
        self::assertSame('expired', $this->row($first->id->toString())['status'], 'written as it is once somebody came across it');
        self::assertSame('invited', $this->row($second->id->toString())['status']);
    }

    public function testExpiryIsCheckedOnRead(): void
    {
        $invitedAt = new DateTimeImmutable('2026-10-13 18:00:00');
        $round = $this->entityManager->find(CompetitionRound::class, self::ROUND);
        $participant = $this->entityManager->find(CompetitionParticipant::class, self::OLIVIA);
        self::assertNotNull($round);
        self::assertNotNull($participant);
        $invite = new StudioInvite(Uuid::uuid7(), $round, $participant, null, $invitedAt);

        self::assertSame(StudioInviteStatus::Invited, $invite->statusAt($invitedAt->modify('+119 seconds')));
        self::assertTrue($invite->isOpenAt($invitedAt->modify('+119 seconds')));
        self::assertSame(StudioInviteStatus::Expired, $invite->statusAt($invitedAt->modify('+120 seconds')));
        self::assertFalse($invite->isOpenAt($invitedAt->modify('+2 hours')));
        self::assertSame(StudioInviteStatus::Invited, $invite->status, 'nothing written by reading');

        // Answered in time, it never runs out
        $invite->join($invitedAt->modify('+30 seconds'));
        self::assertSame(StudioInviteStatus::Joined, $invite->statusAt($invitedAt->modify('+2 hours')));
    }

    public function testOnlyTheEnteredParticipantsOfTheCompetition(): void
    {
        self::turnRoundVideoOn($this->connection);

        try {
            // Nora is a participant, not entered in the round
            $this->invite(OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
            self::fail('Nora is not entered');
        } catch (StudioInviteRefused $refused) {
            self::assertSame(StudioInviteRefused::NOT_ENTERED, $refused->reason);
        }

        $this->expectException(CompetitionParticipantNotFound::class);
        $this->invite(OfficialResultsFixture::PARTICIPANT_ANNA);
    }

    public function testJoiningPutsTheParticipantOnAirAndTheSameAnswerAgainChangesNothing(): void
    {
        self::turnRoundVideoOn($this->connection);
        $inviteId = $this->invite(self::OLIVIA)->id->toString();

        $joined = $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Join);

        self::assertSame(StudioInviteStatus::Joined, $joined->status);
        $row = $this->row($inviteId);
        self::assertSame('joined', $row['status']);
        self::assertNotNull($row['answered_at']);
        // On air once LiveKit sees the guest in the studio
        self::assertNull($row['on_air_from']);

        // A double tap
        self::assertSame(StudioInviteStatus::Joined, $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Join)->status);
        self::assertSame($row['answered_at'], $this->row($inviteId)['answered_at']);

        // Changing the mind is too late
        $this->expectExceptionObject(StudioInviteRefused::because(StudioInviteRefused::NOT_OPEN));
        $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Decline);
    }

    public function testDecliningAndTheRefusedAnswers(): void
    {
        self::turnRoundVideoOn($this->connection);
        $inviteId = $this->invite(self::OLIVIA)->id->toString();

        // Only Olivia's own
        try {
            $this->answer($inviteId, self::ARTHUR, StudioInviteAnswer::Join);
            self::fail('Arthur answered Olivia\'s invitation');
        } catch (StudioInviteNotFound) {
        }

        try {
            $this->answer(Uuid::uuid7()->toString(), self::OLIVIA, StudioInviteAnswer::Join);
            self::fail('No such invitation');
        } catch (StudioInviteNotFound) {
        }

        self::assertSame(StudioInviteStatus::Declined, $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Decline)->status);
        self::assertSame('declined', $this->row($inviteId)['status']);
        self::assertNotNull($this->row($inviteId)['answered_at']);

        $this->expectExceptionObject(StudioInviteRefused::because(StudioInviteRefused::NOT_OPEN));
        $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Join);
    }

    public function testAnAnswerAfterTheExpiryIsRefused(): void
    {
        self::turnRoundVideoOn($this->connection);
        $inviteId = $this->invite(self::OLIVIA)->id->toString();
        $this->age($inviteId, 121);

        try {
            $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Join);
            self::fail('Expired');
        } catch (StudioInviteRefused $refused) {
            self::assertSame(StudioInviteRefused::EXPIRED, $refused->reason);
        }

        self::assertSame('invited', $this->row($inviteId)['status'], 'a refusal writes nothing');
    }

    public function testJoiningNeedsTheVideoStillOnDecliningDoesNot(): void
    {
        self::turnRoundVideoOn($this->connection);
        $inviteId = $this->invite(self::OLIVIA)->id->toString();
        $this->connection->update('online_round', ['video_enabled' => false], ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO], ['video_enabled' => 'boolean']);
        $this->entityManager->clear();

        try {
            $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Join);
            self::fail('The video was turned off');
        } catch (StudioInviteRefused $refused) {
            self::assertSame(StudioInviteRefused::VIDEO_UNAVAILABLE, $refused->reason);
        }

        self::assertSame(StudioInviteStatus::Declined, $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Decline)->status);
    }

    public function testTheHostsThankYouTakesTheGuestOffAir(): void
    {
        self::turnRoundVideoOn($this->connection);
        $inviteId = $this->invite(self::OLIVIA)->id->toString();
        $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Join);

        $ended = $this->end($inviteId);

        self::assertTrue($ended->wasOnAir);
        self::assertSame(StudioInviteStatus::Ended, $ended->invite->status);
        $row = $this->row($inviteId);
        self::assertSame('ended', $row['status']);
        self::assertNotNull($row['on_air_to']);

        // A second click, another host: nothing more
        $again = $this->end($inviteId);
        self::assertFalse($again->wasOnAir);
        self::assertSame($row, $this->row($inviteId));
    }

    public function testEndingAnInvitationNotAnsweredWithdrawsItAndOneThatRanOutStaysExpired(): void
    {
        self::turnRoundVideoOn($this->connection);
        $waiting = $this->invite(self::OLIVIA)->id->toString();

        $withdrawn = $this->end($waiting);
        self::assertFalse($withdrawn->wasOnAir);
        self::assertSame('ended', $this->row($waiting)['status']);
        self::assertNull($this->row($waiting)['on_air_to'], 'never on air');

        $ranOut = $this->invite(self::ARTHUR)->id->toString();
        $this->age($ranOut, 300);
        self::assertSame(StudioInviteStatus::Expired, $this->end($ranOut)->invite->status);
        self::assertSame('expired', $this->row($ranOut)['status']);

        $this->expectException(StudioInviteNotFound::class);
        $this->end(Uuid::uuid7()->toString());
    }

    public function testLiveKitSeeingTheGuestKeepsTheTimeOnAir(): void
    {
        self::turnRoundVideoOn($this->connection);
        $inviteId = $this->invite(self::OLIVIA)->id->toString();
        $joinedAt = new DateTimeImmutable('2026-10-13 18:00:05');

        // Not joined yet: LiveKit's word changes nothing
        $this->presence(self::OLIVIA, true, $joinedAt);
        self::assertNull($this->row($inviteId)['on_air_from']);

        $this->answer($inviteId, self::OLIVIA, StudioInviteAnswer::Join);
        $this->presence(self::OLIVIA, true, $joinedAt);
        self::assertSame('2026-10-13 18:00:05', $this->row($inviteId)['on_air_from']);

        // A webhook again, a reconnect - the first time counts
        $this->presence(self::OLIVIA, true, $joinedAt->modify('+40 seconds'));
        self::assertSame('2026-10-13 18:00:05', $this->row($inviteId)['on_air_from']);

        // Somebody else, another round: nothing
        $this->presence(self::ARTHUR, false, $joinedAt->modify('+60 seconds'));
        $this->messageBus->dispatch(new RecordStudioGuestPresence(Uuid::uuid7()->toString(), self::OLIVIA, false, $joinedAt));
        self::assertSame('joined', $this->row($inviteId)['status']);

        $this->presence(self::OLIVIA, false, $joinedAt->modify('+5 minutes'));
        $row = $this->row($inviteId);
        self::assertSame('ended', $row['status']);
        self::assertSame('2026-10-13 18:05:05', $row['on_air_to']);

        // Delivered twice
        $this->presence(self::OLIVIA, false, $joinedAt->modify('+6 minutes'));
        self::assertSame('2026-10-13 18:05:05', $this->row($inviteId)['on_air_to']);
    }

    public function testTwoHostsInvitingAtOnceTakeTurnsUnderTheEventsLock(): void
    {
        $message = new InviteToStudio(strtoupper(self::COMPETITION), self::ROUND, self::OLIVIA, self::HOST);

        self::assertSame(CompetitionParticipantsLock::key(self::COMPETITION), $message->lockKey());
    }

    private function invite(string $participantId): StudioInvite
    {
        $invite = $this->messageBus->dispatch(new InviteToStudio(self::COMPETITION, self::ROUND, $participantId, self::HOST))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StudioInvite::class, $invite);

        return $invite;
    }

    private function answer(string $inviteId, string $participantId, StudioInviteAnswer $answer): StudioInvite
    {
        $invite = $this->messageBus->dispatch(new AnswerStudioInvite($inviteId, $participantId, $answer))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StudioInvite::class, $invite);

        return $invite;
    }

    private function end(string $inviteId): EndedStudioGuest
    {
        $ended = $this->messageBus->dispatch(new EndStudioGuest($inviteId, self::HOST))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(EndedStudioGuest::class, $ended);

        return $ended;
    }

    private function presence(string $participantId, bool $present, DateTimeImmutable $at): void
    {
        $this->messageBus->dispatch(new RecordStudioGuestPresence(self::ROUND, $participantId, $present, $at));
    }

    /**
     * The invitation as if it was sent $seconds earlier
     */
    private function age(string $inviteId, int $seconds): void
    {
        $this->connection->executeStatement(
            "UPDATE studio_invite SET invited_at = invited_at - make_interval(secs => :seconds) WHERE id = :id",
            ['seconds' => $seconds, 'id' => $inviteId],
        );
        $this->entityManager->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $inviteId): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM studio_invite WHERE id = :id', ['id' => $inviteId]);
        self::assertIsArray($row);

        return $row;
    }

    private function inviteCount(): mixed
    {
        return $this->connection->fetchOne('SELECT COUNT(*) FROM studio_invite');
    }
}
