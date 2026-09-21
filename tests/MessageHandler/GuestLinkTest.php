<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\GuestLinkNotPossible;
use SpeedPuzzling\Web\Exceptions\GuestLinkRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AnswerGuestLink;
use SpeedPuzzling\Web\Message\RequestGuestLink;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "This guest of mine is you": a guest becomes a registered player - only when that player says yes.
 */
final class GuestLinkTest extends KernelTestCase
{
    private const string ASKER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string ASKER_USER_ID = PlayerFixture::PLAYER_WITH_STRIPE_USER_ID;
    private const string ASKED = PlayerFixture::PLAYER_WITH_FAVORITES;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testNothingChangesUntilTheAskedPlayerAgrees(): void
    {
        $time = $this->addTime(['Michael']);
        $teamBefore = $this->teamIdOf($time);

        $requestId = $this->ask('g:michael', '#player3');

        self::assertSame($teamBefore, $this->teamIdOf($time));
        self::assertSame(['Michael'], $this->guestNamesOf($time));
        self::assertSame(
            [['player_id' => self::ASKED, 'actor_player_id' => self::ASKER]],
            $this->database->fetchAllAssociative("SELECT player_id, actor_player_id FROM notification WHERE type = 'GuestLinkRequested' AND target_guest_link_request_id = :id", ['id' => $requestId]),
        );
    }

    public function testAcceptingMakesTheGuestThePlayerEverywhereTheyPuzzledTogether(): void
    {
        $pairTime = $this->addTime(['Michael']);
        $trioTime = $this->addTime(['michael', '#admin']);
        // The two already have a real pair: the guest pair must merge into it
        $realPairTime = $this->addTime(['#player3']);
        $realPair = $this->teamIdOf($realPairTime);

        $requestId = $this->ask('g:michael', 'player3');
        $this->messageBus->dispatch(new AnswerGuestLink($requestId, self::ASKED, true));

        self::assertSame($realPair, $this->teamIdOf($pairTime), 'The guest pair became the pair the two already had');
        self::assertSame([], $this->guestNamesOf($pairTime));
        self::assertSame([], $this->guestNamesOf($trioTime));

        $trioMembers = $this->database->fetchFirstColumn('SELECT player_id FROM puzzling_team_member WHERE team_id = :id ORDER BY member_key', ['id' => $this->teamIdOf($trioTime)]);
        self::assertEqualsCanonicalizing([self::ASKER, self::ASKED, PlayerFixture::PLAYER_ADMIN], $trioMembers);

        // The results are part of the player's own history now
        $duoOfAsked = self::getContainer()->get(GetPlayerSolvedPuzzles::class)->duoByPlayerId(self::ASKED);
        self::assertEqualsCanonicalizing([$pairTime, $realPairTime], array_map(static fn($puzzle): string => $puzzle->timeId, $duoOfAsked));

        // …and whoever asked is told
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'GuestLinkAccepted' AND player_id = :asker AND actor_player_id = :asked", ['asker' => self::ASKER, 'asked' => self::ASKED]));
        self::assertTrue($this->database->fetchOne('SELECT accepted FROM guest_link_request WHERE id = :id', ['id' => $requestId]));
    }

    public function testDecliningChangesNothingAndTellsNobody(): void
    {
        $time = $this->addTime(['Michael']);
        $requestId = $this->ask('g:michael', '#player3');

        $this->messageBus->dispatch(new AnswerGuestLink($requestId, self::ASKED, false));

        self::assertSame(['Michael'], $this->guestNamesOf($time));
        self::assertFalse($this->database->fetchOne('SELECT accepted FROM guest_link_request WHERE id = :id', ['id' => $requestId]));
        self::assertSame(0, $this->database->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'GuestLinkAccepted'"));
    }

    public function testOnlyWhoeverWasAskedMayAnswerAndOnlyOnce(): void
    {
        $this->addTime(['Michael']);
        $requestId = $this->ask('g:michael', '#player3');

        try {
            $this->messageBus->dispatch(new AnswerGuestLink($requestId, PlayerFixture::PLAYER_ADMIN, true));
            self::fail('Somebody else answered the question');
        } catch (GuestLinkRequestNotFound) {
        }

        $this->messageBus->dispatch(new AnswerGuestLink($requestId, self::ASKED, false));

        $this->expectException(GuestLinkRequestNotFound::class);
        $this->messageBus->dispatch(new AnswerGuestLink($requestId, self::ASKED, true));
    }

    public function testAskingAgainReplacesTheOpenQuestion(): void
    {
        $this->addTime(['Michael']);
        $first = $this->ask('g:michael', '#player3');
        $second = $this->ask('g:michael', '#admin');

        self::assertSame([$second], $this->database->fetchFirstColumn('SELECT id FROM guest_link_request WHERE requester_id = :id', ['id' => self::ASKER]));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM notification WHERE target_guest_link_request_id = :id', ['id' => $first]));
    }

    public function testSomebodyElsesGuestOrOneselfCannotBeAskedAbout(): void
    {
        $this->addTime(['Michael']);

        foreach ([['g:nobody-of-mine', '#player3'], ['g:michael', '#player4']] as [$guestKey, $code]) {
            try {
                $this->ask($guestKey, $code);
                self::fail('The question should have been refused');
            } catch (HandlerFailedException $exception) {
                self::assertInstanceOf(GuestLinkNotPossible::class, $exception->getPrevious());
            }
        }

        $this->expectException(PlayerNotFound::class);
        $this->ask('g:michael', '#no-such-code');
    }

    public function testPlayerWhoBlocksTheAskerIsNeverAsked(): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::ASKED, 'blocked' => self::ASKER],
        );
        $this->addTime(['Michael']);

        // No error - for the asker it looks like a question nobody answered
        $this->ask('g:michael', '#player3');

        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM guest_link_request'));
        self::assertSame(0, $this->database->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'GuestLinkRequested'"));
    }

    public function testPlayerAlreadyInTheTeamIsNotAddedASecondTime(): void
    {
        // Michael the account and "Michael" the guest in one team: two people, whatever the asker believes
        $time = $this->addTime(['#player3', 'Michael']);
        $team = $this->teamIdOf($time);

        $requestId = $this->ask('g:michael', '#player3');
        $this->messageBus->dispatch(new AnswerGuestLink($requestId, self::ASKED, true));

        self::assertSame($team, $this->teamIdOf($time));
        self::assertSame(['Michael'], $this->guestNamesOf($time));
        self::assertSame(3, $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team_member WHERE team_id = :id', ['id' => $team]));
    }

    private function ask(string $guestKey, string $code): string
    {
        $requestId = Uuid::uuid7();
        $this->messageBus->dispatch(new RequestGuestLink($requestId, self::ASKER, $guestKey, $code));

        return $requestId->toString();
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addTime(array $groupPlayers): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: self::ASKER_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function teamIdOf(string $timeId): string
    {
        $teamId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($teamId);

        return $teamId;
    }

    /**
     * @return list<string>
     */
    private function guestNamesOf(string $timeId): array
    {
        /** @var list<string> $names */
        $names = $this->database->fetchFirstColumn(
            "SELECT puzzler ->> 'player_name' FROM puzzle_solving_time, json_array_elements(team -> 'puzzlers') AS puzzler WHERE id = :id AND puzzler ->> 'player_id' IS NULL",
            ['id' => $timeId],
        );

        return $names;
    }
}
