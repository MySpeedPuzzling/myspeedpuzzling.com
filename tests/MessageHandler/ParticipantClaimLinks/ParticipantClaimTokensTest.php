<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\ParticipantClaimLinks;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantAlreadyLinked;
use SpeedPuzzling\Web\Message\ClaimParticipantByToken;
use SpeedPuzzling\Web\Message\GenerateParticipantClaimTokens;
use SpeedPuzzling\Web\Message\RegenerateParticipantClaimToken;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantLinks;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture as Wjpc;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ClaimLinkOutcome;
use SpeedPuzzling\Web\Value\OnlineToken;
use SpeedPuzzling\Web\Value\ParticipantLinkAction;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Personal claim links (docs/features/competitions-management/participant-linking.md D5, §5.2, R6): made for the people
 * not linked, replaced on "New link", and the link itself - proof from the organiser, so it links at once under every
 * policy and past every refusal; a used link never links a second person. WJPC 2024: PARTICIPANT_UNCONNECTED nobody's,
 * PARTICIPANT_CONNECTED the organiser's row of PLAYER_REGULAR (import), PARTICIPANT_SELF_JOINED PLAYER_WITH_FAVORITES'
 * own row, PARTICIPANT_DELETED removed.
 */
final class ParticipantClaimTokensTest extends KernelTestCase
{
    private const string EVENT = CompetitionFixture::COMPETITION_WJPC_2024;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTokensAreMadeForThePeopleNotLinkedOnly(): void
    {
        self::assertSame(1, $this->generate());

        $token = $this->token(Wjpc::PARTICIPANT_UNCONNECTED);
        self::assertNotNull($token);
        self::assertTrue(OnlineToken::looksValid($token));
        self::assertNull($this->token(Wjpc::PARTICIPANT_CONNECTED));
        self::assertNull($this->token(Wjpc::PARTICIPANT_SELF_JOINED));
        self::assertNull($this->token(Wjpc::PARTICIPANT_DELETED));

        // A link once made stays
        self::assertSame(0, $this->generate());
        self::assertSame($token, $this->token(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testOnlyTheListedPeopleGetOne(): void
    {
        self::assertSame(0, $this->generate([Wjpc::PARTICIPANT_CONNECTED, Wjpc::PARTICIPANT_DELETED]));
        self::assertNull($this->token(Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertSame(1, $this->generate([Wjpc::PARTICIPANT_UNCONNECTED]));
        self::assertNotNull($this->token(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testANewLinkReplacesTheOldOneWhichStopsWorking(): void
    {
        $this->generate();
        $old = $this->token(Wjpc::PARTICIPANT_UNCONNECTED);
        self::assertNotNull($old);

        $new = $this->messageBus->dispatch(new RegenerateParticipantClaimToken(self::EVENT, Wjpc::PARTICIPANT_UNCONNECTED))->last(HandledStamp::class)?->getResult();

        self::assertIsString($new);
        self::assertNotSame($old, $new);
        self::assertSame($new, $this->token(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame(ClaimLinkOutcome::NotValid, $this->claim($old, PlayerFixture::PLAYER_ADMIN));
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame(ClaimLinkOutcome::Linked, $this->claim($new, PlayerFixture::PLAYER_ADMIN));
    }

    public function testANewLinkIsForSomebodyOnTheListNotLinked(): void
    {
        try {
            $this->messageBus->dispatch(new RegenerateParticipantClaimToken(self::EVENT, Wjpc::PARTICIPANT_CONNECTED));
            self::fail('A linked participant got a claim link');
        } catch (ParticipantAlreadyLinked) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->messageBus->dispatch(new RegenerateParticipantClaimToken(self::EVENT, Wjpc::PARTICIPANT_DELETED));
            self::fail('A removed participant got a claim link');
        } catch (CompetitionParticipantNotFound) {
        }

        // Another event's participant
        try {
            $this->messageBus->dispatch(new RegenerateParticipantClaimToken(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, Wjpc::PARTICIPANT_UNCONNECTED));
            self::fail('Another event\'s participant got a claim link');
        } catch (CompetitionParticipantNotFound) {
        }
    }

    public function testTheLinkLinksAtOnceWithItsMethodAndALogRow(): void
    {
        $token = $this->tokenOfUnconnected();

        self::assertSame(ClaimLinkOutcome::Linked, $this->claim($token, PlayerFixture::PLAYER_ADMIN));

        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame('claim_link', $this->column(Wjpc::PARTICIPANT_UNCONNECTED, 'connected_via'));
        self::assertSame(
            ['action' => 'linked', 'method' => 'claim_link', 'player_id' => PlayerFixture::PLAYER_ADMIN, 'by_player_id' => PlayerFixture::PLAYER_ADMIN],
            $this->database->fetchAssociative(
                'SELECT action, method, player_id, by_player_id FROM participant_link_log WHERE participant_id = :id ORDER BY at DESC, id DESC LIMIT 1',
                ['id' => Wjpc::PARTICIPANT_UNCONNECTED],
            ),
        );
        // The row is the player's own now: the event page may hand them the online personal link (§5.6)
        self::assertNotNull($this->column(Wjpc::PARTICIPANT_UNCONNECTED, 'online_link_confirmed_at'));

        // Opened again: nothing changes
        self::assertSame(ClaimLinkOutcome::AlreadyYours, $this->claim($token, PlayerFixture::PLAYER_ADMIN));
    }

    /**
     * The organiser's token is proof: the approval policy, a refused pair and the 3-refusal limit do not apply (§5.2)
     */
    public function testThePolicyAndRefusalsAreNeverConsulted(): void
    {
        $token = $this->tokenOfUnconnected();
        $this->database->executeStatement("UPDATE competition SET participant_claims = 'approval' WHERE id = :id", ['id' => self::EVENT]);
        $this->database->executeStatement(
            "INSERT INTO participant_link_log (id, competition_id, participant_id, participant_name, player_id, player_name, action, method, by_player_id, at)
             VALUES (gen_uuid_v7(), :competition, :participant, 'x', :player, 'y', 'not_this_person', NULL, NULL, " . TestClock::SQL_NOW . ')',
            ['competition' => self::EVENT, 'participant' => Wjpc::PARTICIPANT_UNCONNECTED, 'player' => PlayerFixture::PLAYER_ADMIN],
        );

        self::assertSame(ClaimLinkOutcome::Linked, $this->claim($token, PlayerFixture::PLAYER_ADMIN));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM participant_link_request WHERE competition_id = :id', ['id' => self::EVENT]));
    }

    public function testAUsedLinkNeverLinksASecondPerson(): void
    {
        $token = $this->tokenOfUnconnected();
        $this->claim($token, PlayerFixture::PLAYER_ADMIN);

        self::assertSame(ClaimLinkOutcome::BelongsToOther, $this->claim($token, PlayerFixture::PLAYER_PRIVATE));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testSomebodyOnTheListAsAnotherPersonIsAskedToSwitch(): void
    {
        $token = $this->tokenOfUnconnected();

        self::assertSame(ClaimLinkOutcome::IsOtherParticipant, $this->claim($token, PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->linkedPlayer(Wjpc::PARTICIPANT_SELF_JOINED));

        // "Switch": their own self-joined row goes (no official data), the organiser's row is theirs
        self::assertSame(ClaimLinkOutcome::Linked, $this->claim($token, PlayerFixture::PLAYER_WITH_FAVORITES, switch: true));
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertNotNull($this->column(Wjpc::PARTICIPANT_SELF_JOINED, 'deleted_at'));
    }

    public function testAnOrganisersRowOfThePlayerIsOnlyLetGoOfOnASwitch(): void
    {
        $token = $this->tokenOfUnconnected();

        self::assertSame(ClaimLinkOutcome::Linked, $this->claim($token, PlayerFixture::PLAYER_REGULAR, switch: true));
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_CONNECTED));
        self::assertNull($this->column(Wjpc::PARTICIPANT_CONNECTED, 'deleted_at'));
    }

    public function testUnknownRemovedAndHiddenLinksAreNotValid(): void
    {
        self::assertSame(ClaimLinkOutcome::NotValid, $this->claim('UnknownCa1mTokenHere22', PlayerFixture::PLAYER_ADMIN));
        self::assertSame(ClaimLinkOutcome::NotValid, $this->claim('not a token', PlayerFixture::PLAYER_ADMIN));

        $token = $this->tokenOfUnconnected();

        // Asked for under another event (the page read another event for it)
        self::assertSame(ClaimLinkOutcome::NotValid, $this->claim($token, PlayerFixture::PLAYER_ADMIN, competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));

        // A draft event: nobody links (P17)
        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::EVENT]);
        self::assertSame(ClaimLinkOutcome::NotValid, $this->claim($token, PlayerFixture::PLAYER_ADMIN));
        $this->database->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => self::EVENT]);

        // Removed from the list
        $this->database->executeStatement('UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => Wjpc::PARTICIPANT_UNCONNECTED]);
        self::assertSame(ClaimLinkOutcome::NotValid, $this->claim($token, PlayerFixture::PLAYER_ADMIN));
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    /**
     * R6: an unlink clears the token only when the link was made with it - a slip of somebody the organiser linked by
     * hand keeps working once they are unlinked again
     */
    public function testAnUnlinkClearsTheTokenOnlyWhenTheLinkWasMadeWithIt(): void
    {
        $token = $this->tokenOfUnconnected();
        $this->claim($token, PlayerFixture::PLAYER_ADMIN);
        $this->unlink(Wjpc::PARTICIPANT_UNCONNECTED);

        self::assertNull($this->token(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame(ClaimLinkOutcome::NotValid, $this->claim($token, PlayerFixture::PLAYER_PRIVATE));

        // Linked by the organiser while holding a claim link: the unlink keeps it
        $this->generate();
        $second = $this->token(Wjpc::PARTICIPANT_UNCONNECTED);
        self::assertNotNull($second);
        $this->link(Wjpc::PARTICIPANT_UNCONNECTED, PlayerFixture::PLAYER_ADMIN, ParticipantLinkMethod::Organiser);
        $this->unlink(Wjpc::PARTICIPANT_UNCONNECTED);

        self::assertSame($second, $this->token(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame(ClaimLinkOutcome::Linked, $this->claim($second, PlayerFixture::PLAYER_ADMIN));
    }

    /**
     * §5.6: on an event with online timing the claim link hands the online personal link too - the row is the player's
     * own, like opening the personal link signed in
     */
    public function testAnOnlineEventHandsTheOnlineLink(): void
    {
        $event = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
        $participant = OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN;
        // The test cup is a draft - published here
        $this->database->executeStatement('UPDATE competition SET is_draft = false, approved_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $event]);
        $this->messageBus->dispatch(new GenerateParticipantClaimTokens($event, [$participant]));
        $token = $this->token($participant);
        self::assertNotNull($token);
        self::assertNull($this->column($participant, 'online_token'));

        self::assertSame(ClaimLinkOutcome::Linked, $this->claim($token, PlayerFixture::PLAYER_ADMIN, competitionId: $event));

        self::assertNotNull($this->column($participant, 'online_token'));
        $row = self::getContainer()->get(CompetitionParticipantRepository::class)->get($participant);
        self::getContainer()->get(CompetitionParticipantRepository::class)->refresh($row);
        self::assertTrue($row->hasConfirmedOnlineLink());
    }

    /**
     * @param null|list<string> $participantIds
     */
    private function generate(null|array $participantIds = null): int
    {
        $given = $this->messageBus->dispatch(new GenerateParticipantClaimTokens(self::EVENT, $participantIds))->last(HandledStamp::class)?->getResult();
        self::assertIsInt($given);

        return $given;
    }

    private function tokenOfUnconnected(): string
    {
        $this->generate([Wjpc::PARTICIPANT_UNCONNECTED]);
        $token = $this->token(Wjpc::PARTICIPANT_UNCONNECTED);
        self::assertNotNull($token);

        return $token;
    }

    private function claim(string $token, string $playerId, bool $switch = false, string $competitionId = self::EVENT): ClaimLinkOutcome
    {
        $outcome = $this->messageBus->dispatch(new ClaimParticipantByToken($competitionId, $token, $playerId, $switch))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ClaimLinkOutcome::class, $outcome);

        return $outcome;
    }

    private function unlink(string $participantId): void
    {
        $participant = self::getContainer()->get(CompetitionParticipantRepository::class)->get($participantId);
        self::getContainer()->get(CompetitionParticipantRepository::class)->refresh($participant);
        self::getContainer()->get(ParticipantLinks::class)->unlink($participant, ParticipantLinkAction::Unlinked, null, TestClock::now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function link(string $participantId, string $playerId, ParticipantLinkMethod $via): void
    {
        $participant = self::getContainer()->get(CompetitionParticipantRepository::class)->get($participantId);
        self::getContainer()->get(CompetitionParticipantRepository::class)->refresh($participant);
        $player = self::getContainer()->get(PlayerRepository::class)->get($playerId);
        self::getContainer()->get(ParticipantLinks::class)->link($participant, $player, $via, null, TestClock::now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function token(string $participantId): null|string
    {
        $token = $this->column($participantId, 'claim_token');

        return is_string($token) ? $token : null;
    }

    private function linkedPlayer(string $participantId): null|string
    {
        $playerId = $this->column($participantId, 'player_id');

        return is_string($playerId) ? $playerId : null;
    }

    private function column(string $participantId, string $column): mixed
    {
        return $this->database->fetchOne('SELECT ' . $column . ' FROM competition_participant WHERE id = :id', ['id' => $participantId]);
    }
}
