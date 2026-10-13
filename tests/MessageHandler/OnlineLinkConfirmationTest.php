<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\ConfirmOnlineLink;
use SpeedPuzzling\Web\Message\ConnectCompetitionParticipant;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Whose personal link is it (docs/features/online-competitions/README.md §5.4, CompetitionParticipant::
 * $onlineLinkConfirmedAt): the event page hands a link only to a player who showed it is theirs - opened it signed in
 * (ConfirmOnlineLink, from compete_connect) or made the row themselves ("I'm going"). A row claimed from the organiser's
 * list is no proof: claiming never confirms, and every new connection clears an old confirmation.
 */
final class OnlineLinkConfirmationTest extends KernelTestCase
{
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        // Joining needs a public event - the published test cups (from #2 on)
        $this->database->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => self::COMPETITION]);
    }

    public function testOpeningTheLinkSignedInConfirmsItForTheConnectedPlayerOnly(): void
    {
        // The organiser connects Arthur's row to a player (the participants sheet does it the same way)
        $this->messageBus->dispatch(new ConnectCompetitionParticipant(self::COMPETITION, PlayerFixture::PLAYER_WITH_FAVORITES, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));
        self::assertNull($this->confirmedAt(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));

        // Somebody else holding the link confirms nothing
        self::assertFalse($this->confirm(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, PlayerFixture::PLAYER_PRIVATE));
        self::assertNull($this->confirmedAt(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));

        self::assertTrue($this->confirm(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertNotNull($this->confirmedAt(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));

        // Confirmed for this connection already (both moved to a known moment): opening it again writes nothing
        $this->database->executeStatement(
            "UPDATE competition_participant SET connected_at = '2026-01-01 10:00:00', online_link_confirmed_at = '2026-01-01 10:05:00' WHERE id = :id",
            ['id' => OnlineRoundsFixture::PARTICIPANT_ANONYMOUS],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertTrue($this->confirm(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertSame('2026-01-01 10:05:00', $this->confirmedAt(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));
    }

    public function testEveryNewConnectionClearsTheConfirmation(): void
    {
        $player = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        $participant = new CompetitionParticipant(
            Uuid::uuid7(),
            'Someone',
            null,
            self::getContainer()->get(CompetitionRepository::class)->get(self::COMPETITION),
        );
        $now = new DateTimeImmutable('2026-10-13 12:00:00');

        $participant->connect($player, $now);
        $participant->confirmOnlineLink($now);
        self::assertTrue($participant->hasConfirmedOnlineLink());

        $participant->connect($player, $now->modify('+1 second'));
        self::assertFalse($participant->hasConfirmedOnlineLink(), 'connected again - not confirmed for this connection');

        $participant->confirmOnlineLink($now->modify('+2 seconds'));
        $participant->disconnect();
        self::assertNull($participant->onlineLinkConfirmedAt);
        self::assertFalse($participant->hasConfirmedOnlineLink());

        // A confirmation older than the connection never counts (a connection written around the entity)
        $participant->connect($player, $now->modify('+10 seconds'));
        $participant->confirmOnlineLink($now->modify('+5 seconds'));
        self::assertFalse($participant->hasConfirmedOnlineLink());
    }

    public function testPickingANameFromTheOrganisersListNeverConfirmsItsLink(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(self::COMPETITION, PlayerFixture::PLAYER_WITH_FAVORITES, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));

        $row = $this->row(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $row['player_id']);
        self::assertNull($row['online_link_confirmed_at']);
        // The link stays the organiser's to hand out
        self::assertSame(OnlineRoundsFixture::TOKEN_ANONYMOUS, $row['online_token']);
    }

    public function testJoiningByThemselvesConfirmsTheRowAndGivesTheLinkAtOnce(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(self::COMPETITION, PlayerFixture::PLAYER_WITH_FAVORITES));

        $row = $this->ownRow(PlayerFixture::PLAYER_WITH_FAVORITES, self::COMPETITION);
        self::assertSame('self_joined', $row['source']);
        self::assertNotNull($row['online_link_confirmed_at']);
        self::assertIsString($row['online_token']);
        self::assertTrue(OnlineToken::looksValid($row['online_token']));
    }

    public function testAnInPersonEventConfirmsTheRowButHasNoLink(): void
    {
        // PLAYER_ADMIN is nobody at WJPC 2024
        $this->messageBus->dispatch(new JoinCompetition(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_ADMIN));

        $row = $this->ownRow(PlayerFixture::PLAYER_ADMIN, CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNotNull($row['online_link_confirmed_at']);
        self::assertNull($row['online_token']);
    }

    public function testAPlayerOnTheWaitlistGetsTheLinkWithTheSpot(): void
    {
        // Three going already - full at two
        $this->messageBus->dispatch(new ChangeCompetitionRegistrationSettings(
            competitionId: self::COMPETITION,
            registrationManaged: true,
            capacity: 2,
            registrationOpensAt: null,
            registrationClosesAt: null,
            timezone: 'Europe/Prague',
            entryFeeText: null,
            paymentInstructions: null,
        ));

        $this->messageBus->dispatch(new JoinCompetition(self::COMPETITION, PlayerFixture::PLAYER_WITH_FAVORITES));
        $row = $this->ownRow(PlayerFixture::PLAYER_WITH_FAVORITES, self::COMPETITION);
        self::assertSame('waitlisted', $row['registration_status']);
        self::assertNotNull($row['online_link_confirmed_at']);
        self::assertNull($row['online_token'], 'no spot, no link');

        $this->messageBus->dispatch(new PromoteParticipantFromWaitlist(self::COMPETITION, $row['id']));

        $row = $this->ownRow(PlayerFixture::PLAYER_WITH_FAVORITES, self::COMPETITION);
        self::assertSame('reserved', $row['registration_status']);
        self::assertIsString($row['online_token']);
    }

    private function confirm(string $participantId, string $playerId): bool
    {
        $result = $this->messageBus->dispatch(new ConfirmOnlineLink(self::COMPETITION, $participantId, $playerId))
            ->last(HandledStamp::class)?->getResult();
        self::assertIsBool($result);

        return $result;
    }

    private function confirmedAt(string $participantId): null|string
    {
        $value = $this->row($participantId)['online_link_confirmed_at'];

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $participantId): array
    {
        $row = $this->database->fetchAssociative('SELECT * FROM competition_participant WHERE id = :id', ['id' => $participantId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array{id: string, source: string, registration_status: null|string, online_token: null|string, online_link_confirmed_at: null|string}
     */
    private function ownRow(string $playerId, string $competitionId): array
    {
        /** @var false|array{id: string, source: string, registration_status: null|string, online_token: null|string, online_link_confirmed_at: null|string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT id, source, registration_status, online_token, online_link_confirmed_at FROM competition_participant WHERE competition_id = :competition AND player_id = :player AND deleted_at IS NULL',
            ['competition' => $competitionId, 'player' => $playerId],
        );
        self::assertIsArray($row);

        return $row;
    }
}
