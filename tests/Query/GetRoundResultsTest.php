<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\RoundResult;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\RoundResultStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Qualification round of WJPC 2024 (60 min limit, PUZZLE_500_01). Fixture results: PLAYER_ADMIN 1780 s,
 * PLAYER_REGULAR 1850 s, PLAYER_PRIVATE 1920 s.
 */
final class GetRoundResultsTest extends KernelTestCase
{
    private GetRoundResults $getRoundResults;
    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getRoundResults = self::getContainer()->get(GetRoundResults::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testFinishedResultsAreOrderedByTimeAndPrivatePlayersAreHidden(): void
    {
        $results = $this->results(viewerPlayerId: null);

        self::assertSame([PuzzleSolvingTimeFixture::TIME_11, PuzzleSolvingTimeFixture::TIME_09], $this->timeIds($results));
        self::assertSame(RoundResultStatus::Finished, $results[0]->status);
    }

    public function testPrivatePlayerSeesTheirOwnResult(): void
    {
        $results = $this->results(viewerPlayerId: PlayerFixture::PLAYER_PRIVATE);

        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_11, PuzzleSolvingTimeFixture::TIME_09, PuzzleSolvingTimeFixture::TIME_10],
            $this->timeIds($results),
        );
    }

    public function testUnfinishedComeAfterFinishedAndOverLimitComeLast(): void
    {
        $overLimit = $this->addTime(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, '01:10:00');
        $unfinished = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, '00:59:00');
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = NULL, pieces_placed = 450, finished_later_seconds = 3900 WHERE id = :id',
            ['id' => $unfinished],
        );

        $results = $this->results(viewerPlayerId: null);

        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_11, PuzzleSolvingTimeFixture::TIME_09, $unfinished, $overLimit],
            $this->timeIds($results),
        );
        self::assertSame(RoundResultStatus::Unfinished, $results[2]->status);
        self::assertSame(450, $results[2]->piecesPlaced);
        self::assertSame(3900, $results[2]->finishedLaterSeconds);
        self::assertSame(RoundResultStatus::OverLimit, $results[3]->status);
    }

    public function testEarliestTimeOfAPlayerCountsEvenWhenALaterOneIsFaster(): void
    {
        // A practice run after the event on the same puzzle, linked to the competition, much faster
        $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, '00:20:00');

        $results = $this->results(viewerPlayerId: null);

        self::assertSame([PuzzleSolvingTimeFixture::TIME_11, PuzzleSolvingTimeFixture::TIME_09], $this->timeIds($results));
    }

    public function testSuspiciousTimesAreLeftOut(): void
    {
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_11]);

        self::assertSame([PuzzleSolvingTimeFixture::TIME_09], $this->timeIds($this->results(viewerPlayerId: null)));
    }

    public function testBlockedPlayersResultIsHiddenFromTheBlockerOnly(): void
    {
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_09],
            $this->timeIds($this->results(viewerPlayerId: PlayerFixture::PLAYER_REGULAR)),
        );

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_11, PuzzleSolvingTimeFixture::TIME_09],
            $this->timeIds($this->results(viewerPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES)),
        );

        TestingViewer::signOut(self::getContainer());
        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_11, PuzzleSolvingTimeFixture::TIME_09],
            $this->timeIds($this->results(viewerPlayerId: null)),
        );
    }

    public function testGroupWithABlockedMemberIsHiddenUnlessTheViewerTookPart(): void
    {
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->makeGroupTime(PuzzleSolvingTimeFixture::TIME_11, [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE]);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_09],
            $this->timeIds($this->results(viewerPlayerId: PlayerFixture::PLAYER_REGULAR)),
        );

        TestingViewer::signOut(self::getContainer());
        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_11, PuzzleSolvingTimeFixture::TIME_09],
            $this->timeIds($this->results(viewerPlayerId: null)),
        );

        $this->makeGroupTime(
            PuzzleSolvingTimeFixture::TIME_11,
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR],
        );

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertContains(
            PuzzleSolvingTimeFixture::TIME_11,
            $this->timeIds($this->results(viewerPlayerId: PlayerFixture::PLAYER_REGULAR)),
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }

    /**
     * @param list<string> $playerIds
     */
    private function makeGroupTime(string $timeId, array $playerIds): void
    {
        $puzzlers = array_map(
            static fn (string $playerId): array => ['player_id' => $playerId, 'player_name' => null],
            $playerIds,
        );

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET team = :team WHERE id = :id',
            ['team' => json_encode(['team_id' => null, 'puzzlers' => $puzzlers], JSON_THROW_ON_ERROR), 'id' => $timeId],
        );
    }

    /**
     * @return list<RoundResult>
     */
    private function results(null|string $viewerPlayerId): array
    {
        $round = $this->qualificationRound();

        return $this->getRoundResults->forRound($round, $viewerPlayerId)[PuzzleFixture::PUZZLE_500_01] ?? [];
    }

    private function qualificationRound(): EditionRoundDetail
    {
        foreach (self::getContainer()->get(GetEditionRounds::class)->forCompetition(CompetitionFixture::COMPETITION_WJPC_2024) as $round) {
            if ($round->id === CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION) {
                return $round;
            }
        }

        self::fail('Qualification round fixture is missing');
    }

    private function addTime(string $userId, string $time): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: true,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @param list<RoundResult> $results
     * @return list<string>
     */
    private function timeIds(array $results): array
    {
        return array_map(static fn (RoundResult $result): string => $result->timeId, $results);
    }
}
