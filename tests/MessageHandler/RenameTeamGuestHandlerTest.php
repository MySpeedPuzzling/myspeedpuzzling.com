<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RenamePuzzlingTeam;
use SpeedPuzzling\Web\Message\RenameTeamGuest;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A guest's name is free text, so typos split one person into two ("Grandma" / "Granma") - and with
 * them every pair and team they are part of. Renaming brings them back together.
 */
final class RenameTeamGuestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTypoIsFixedEverywhereAndTheSplitTeamsBecomeOne(): void
    {
        $grandma = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Grandma']);
        $grandmaAgain = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Grandma']);
        $typo = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Granma']);
        $typoInTrio = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Granma', '#admin']);

        $grandmaPair = $this->teamIdOf($grandma);
        $typoPair = $this->teamIdOf($typo);
        self::assertNotSame($grandmaPair, $typoPair);
        $this->messageBus->dispatch(new RenamePuzzlingTeam($typoPair, PlayerFixture::PLAYER_WITH_STRIPE, 'Sunday duo'));

        $this->messageBus->dispatch(new RenameTeamGuest(PlayerFixture::PLAYER_WITH_STRIPE, TeamComposition::guestMemberKey('Granma'), 'Grandma'));

        // One pair now, holding all three results - and the name the typo pair had
        self::assertSame($grandmaPair, $this->teamIdOf($typo));
        self::assertSame($grandmaPair, $this->teamIdOf($grandmaAgain));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzling_team WHERE id = :id', ['id' => $typoPair]));
        self::assertSame('Sunday duo', $this->database->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $grandmaPair]));

        // The trio had no twin: it stays, with the member renamed and its key truthful
        $trio = $this->teamIdOf($typoInTrio);
        self::assertSame(
            [['member_key' => 'g:grandma', 'guest_name' => 'Grandma']],
            $this->database->fetchAllAssociative('SELECT member_key, guest_name FROM puzzling_team_member WHERE team_id = :id AND player_id IS NULL', ['id' => $trio]),
        );
        $nextTrioTime = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin', 'grandma']);
        self::assertSame($trio, $this->teamIdOf($nextTrioTime));

        // What the pages display - the group snapshot of each result - says "Grandma" too
        foreach ([$typo, $typoInTrio] as $timeId) {
            self::assertContains('Grandma', $this->guestNamesOf($timeId));
            self::assertNotContains('Granma', $this->guestNamesOf($timeId));
        }

        self::assertSame(0, $this->database->fetchOne("SELECT COUNT(*) FROM puzzling_team_member WHERE member_key = 'g:granma'"));
    }

    public function testChangingOnlyTheSpellingKeepsTheTeam(): void
    {
        $time = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['babicka marie']);
        $team = $this->teamIdOf($time);

        $this->messageBus->dispatch(new RenameTeamGuest(PlayerFixture::PLAYER_WITH_STRIPE, TeamComposition::guestMemberKey('babicka marie'), 'Babička Marie'));

        self::assertSame($team, $this->teamIdOf($time));
        self::assertSame(['Babička Marie'], $this->guestNamesOf($time));
        self::assertSame('Babička Marie', $this->database->fetchOne('SELECT guest_name FROM puzzling_team_member WHERE team_id = :id AND player_id IS NULL', ['id' => $team]));
    }

    public function testSomebodyElsesGuestOfTheSameNameIsLeftAlone(): void
    {
        $mine = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Eva']);
        $theirs = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, ['Eva']);

        $this->messageBus->dispatch(new RenameTeamGuest(PlayerFixture::PLAYER_WITH_STRIPE, TeamComposition::guestMemberKey('Eva'), 'Evička'));

        self::assertSame(['Evička'], $this->guestNamesOf($mine));
        self::assertSame(['Eva'], $this->guestNamesOf($theirs));
    }

    public function testTwoDifferentGuestsOfOneTeamAreNeverCollapsedIntoOne(): void
    {
        $time = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Jana', 'Hana']);
        $team = $this->teamIdOf($time);

        $this->messageBus->dispatch(new RenameTeamGuest(PlayerFixture::PLAYER_WITH_STRIPE, TeamComposition::guestMemberKey('Hana'), 'Jana'));

        // Both were there - they are two people, whatever they are called
        self::assertSame($team, $this->teamIdOf($time));
        self::assertEqualsCanonicalizing(['Jana', 'Hana'], $this->guestNamesOf($time));
        self::assertSame(3, $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team_member WHERE team_id = :id', ['id' => $team]));
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addTime(string $userId, array $groupPlayers): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
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
