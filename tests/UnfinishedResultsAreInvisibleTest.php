<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\MessageHandler\RecalculatePuzzleStatisticsOnSolvingTimeChange;
use SpeedPuzzling\Web\MessageHandler\RemoveFromWishListWhenPuzzleSolved;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetMostActivePlayers;
use SpeedPuzzling\Web\Query\GetMostSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerActivityCalendar;
use SpeedPuzzling\Web\Query\GetPlayerChartData;
use SpeedPuzzling\Web\Query\GetPlayerPuzzleSolves;
use SpeedPuzzling\Web\Query\GetPlayerPuzzleTimes;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerStatistics;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Services\ComputeStatistics;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use SpeedPuzzling\Web\Services\Xp\XpRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\Puzzler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use UnitEnum;

/**
 * The differential test of docs/features/competitions-management/unfinished-results-plan.md ("Refined 2026-11-16",
 * rule 5): the read models of a fixture player, their pair partner, a borrower and the puzzles are captured, unfinished
 * results are added (solo / pair / team, with and without a later total, on a puzzle the player finished before and on
 * puzzles nobody of them finished - one on the wish list, one borrowed), every precompute runs again (puzzle statistics,
 * the Players page's numbers, XP, the wish list handler) and everything is captured again: identical, except "pieces
 * puzzled" and "time spent puzzling", which rise by exactly the pieces placed and the seconds spent of the results each
 * person is in. The control does the same with relax results and must change the counts - so the capture does see the
 * people and the puzzles it claims to.
 */
final class UnfinishedResultsAreInvisibleTest extends KernelTestCase
{
    private const string REGULAR = PlayerFixture::PLAYER_REGULAR;
    private const string PARTNER = PlayerFixture::PLAYER_PRIVATE;
    private const string BORROWER = PlayerFixture::PLAYER_WITH_STRIPE;

    private const array PUZZLES = [
        PuzzleFixture::PUZZLE_500_02, // REGULAR finished it three times
        PuzzleFixture::PUZZLE_3000, // in REGULAR's collection, unsolved; lent to BORROWER, who never solved it
        PuzzleFixture::PUZZLE_1000_01, // REGULAR + PARTNER finished it as a pair (TIME_12)
        PuzzleFixture::PUZZLE_500_04, // on REGULAR's wish list (removed when solved), nobody of them solved it
    ];

    private EntityManagerInterface $entityManager;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testUnfinishedResultsChangeNothingButPiecesPuzzledAndTimeSpent(): void
    {
        $this->recalculate([]);
        $before = $this->capture();

        $added = $this->addResults(unfinished: true);
        $this->recalculate($added);
        $after = $this->capture();

        // [player => [pieces, seconds]] of the added results each person is in, all solved yesterday (this month)
        $solo = [self::REGULAR => [321 + 1234, 3600 + 9000], self::BORROWER => [600, 7200]];
        $duo = [self::REGULAR => [777, 5400], self::PARTNER => [777, 5400]];
        $team = [self::REGULAR => [250, 4000], self::PARTNER => [250, 4000]];
        $everybody = [
            self::REGULAR => [321 + 1234 + 777 + 250, 3600 + 9000 + 5400 + 4000],
            self::PARTNER => [777 + 250, 5400 + 4000],
            self::BORROWER => [600, 7200],
        ];

        $expected = $before;
        $allPieces = 321 + 1234 + 600 + 777 + 250;
        $allSeconds = 3600 + 9000 + 7200 + 5400 + 4000;

        foreach (['global', 'month'] as $scope) {
            self::add($expected, $allPieces, 'statistics', $scope, 'totalPieces');
            self::add($expected, $allSeconds, 'statistics', $scope, 'totalSeconds');
        }

        foreach (['solo' => $solo, 'duo' => $duo, 'team' => $team] as $kindName => $kind) {
            foreach ($kind as $playerId => [$pieces, $seconds]) {
                self::add($expected, $pieces, 'playerStatistics', $playerId, $kindName, 'totalPieces');
                self::add($expected, $seconds, 'playerStatistics', $playerId, $kindName, 'totalSeconds');
            }
        }

        foreach (['mostActiveSolo', 'mostActiveSoloInMonth'] as $board) {
            foreach ($solo as $playerId => [$pieces, $seconds]) {
                self::assertIsArray(self::at($expected, $board, $playerId), 'The capture must see ' . $playerId . ' on ' . $board);
                self::add($expected, $pieces, $board, $playerId, 'totalPiecesCount');
                self::add($expected, $seconds, $board, $playerId, 'totalSeconds');
            }
        }

        foreach ($everybody as $playerId => [$pieces]) {
            foreach (['pieces_total', 'pieces7d', 'pieces_this_month'] as $column) {
                self::add($expected, $pieces, 'communityPlayerStats', $playerId, $column);
            }
        }

        // The profile statistics page (ComputeStatistics): pieces per kind and overall, time spent per day, and the
        // day stays an active day (decision 4 of the plan) - its streak keeps it
        $yesterday = TestClock::now()->modify('-1 day')->format('Y-m-d');

        foreach (['solo' => $solo, 'duo' => $duo, 'team' => $team, 'overall' => $everybody] as $kindName => $kind) {
            [$pieces, $seconds] = $kind[self::REGULAR];
            self::add($expected, $pieces, 'profileStatistics', self::REGULAR, $kindName, 'totalPieces');
            self::add($expected, $seconds, 'profileStatistics', self::REGULAR, $kindName, 'timeSpentSolving', 'perDay', $yesterday);
            self::add($expected, $seconds, 'profileStatistics', self::REGULAR, $kindName, 'timeSpentSolving', 'total');

            if ($kindName !== 'overall') {
                $activeDays = [$yesterday];
                $heldDays = self::at($expected, 'profileStatistics', self::REGULAR, $kindName, 'activeDays');
                self::assertIsArray($heldDays);

                foreach ($heldDays as $day) {
                    self::assertIsString($day);
                    $activeDays[] = $day;
                }

                $activeDays = array_values(array_unique($activeDays));
                sort($activeDays);
                self::put($expected, $activeDays, 'profileStatistics', self::REGULAR, $kindName, 'activeDays');
            }
        }

        // The activity calendar: the day stays active, no solve more, the time spent rises
        if (self::at($expected, 'activityCalendar', self::REGULAR, $yesterday) === null) {
            self::put($expected, [
                'date' => self::at($after, 'activityCalendar', self::REGULAR, $yesterday, 'date'),
                'duoCount' => 0,
                'firstAttemptCount' => 0,
                'soloCount' => 0,
                'teamCount' => 0,
                'totalSeconds' => 0,
            ], 'activityCalendar', self::REGULAR, $yesterday);
        }

        self::add($expected, $everybody[self::REGULAR][1], 'activityCalendar', self::REGULAR, $yesterday, 'totalSeconds');

        self::assertSame(self::normalize($expected), $after);
    }

    public function testTheSameResultsAsRelaxSolvesDoChangeTheCounts(): void
    {
        $this->recalculate([]);
        $before = $this->capture();

        $added = $this->addResults(unfinished: false);
        $this->recalculate($added);
        $after = $this->capture();

        foreach (
            [
            [2, ['playerStatistics', self::REGULAR, 'solo', 'solvedPuzzlesCount']],
            [1, ['playerStatistics', self::PARTNER, 'duo', 'solvedPuzzlesCount']],
            [1, ['playerStatistics', self::PARTNER, 'team', 'solvedPuzzlesCount']],
            [5, ['statistics', 'month', 'solvedPuzzlesCount']],
            [2, ['solvedPuzzlesCount', self::REGULAR]],
            [2, ['communityPlayerStats', self::PARTNER, 'solved_total']],
            ] as [$more, $path]
        ) {
            $was = self::at($before, ...$path);
            self::assertIsInt($was, implode(' / ', $path));
            self::assertSame($was + $more, self::at($after, ...$path), implode(' / ', $path));
        }

        foreach (['unsolved', 'borrowedUnsolved', 'puzzleStatistics', 'wishList', 'xpEntries', 'statsSnapshot'] as $readModel) {
            self::assertNotSame($before[$readModel], $after[$readModel], $readModel);
        }
    }

    /**
     * @return list<string> the added results' ids
     */
    private function addResults(bool $unfinished): array
    {
        $regular = $this->player(self::REGULAR);
        $partner = $this->player(self::PARTNER);
        $borrower = $this->player(self::BORROWER);
        $guest = new Puzzler(playerId: null, playerName: 'Rita Guest', playerCode: null, playerCountry: null, isPrivate: false);

        $results = [
            // Solo, on a puzzle REGULAR finished before
            [$regular, PuzzleFixture::PUZZLE_500_02, null, 321, null, 3600],
            // Solo, finished afterwards, on a puzzle REGULAR never finished (collection, lent out)
            [$regular, PuzzleFixture::PUZZLE_3000, null, 1234, 9000, 9000],
            // Solo, a borrowed puzzle the borrower never finished
            [$borrower, PuzzleFixture::PUZZLE_3000, null, 600, null, 7200],
            // Pair, on a puzzle the pair finished before
            [$regular, PuzzleFixture::PUZZLE_1000_01, [self::puzzler($regular), self::puzzler($partner)], 777, null, 5400],
            // Team with a guest, finished afterwards, on a puzzle on REGULAR's wish list
            [$regular, PuzzleFixture::PUZZLE_500_04, [self::puzzler($regular), self::puzzler($partner), $guest], 250, 4000, 4000],
        ];

        $teamResolver = self::getContainer()->get(PuzzlingTeamResolver::class);
        $solvedAt = TestClock::now()->modify('-1 day');
        $ids = [];

        foreach ($results as [$tracker, $puzzleId, $puzzlers, $piecesPlaced, $finishedLater, $secondsSpent]) {
            $group = $puzzlers === null ? null : new PuzzlersGroup(teamId: null, puzzlers: $puzzlers);
            $time = new PuzzleSolvingTime(
                id: Uuid::uuid7(),
                secondsToSolve: null,
                player: $tracker,
                puzzle: $this->puzzle($puzzleId),
                trackedAt: $solvedAt,
                verified: false,
                team: $group,
                finishedAt: $solvedAt,
                comment: null,
                finishedPuzzlePhoto: null,
                firstAttempt: false,
                unboxed: false,
                piecesPlaced: $unfinished ? $piecesPlaced : null,
                finishedLaterSeconds: $unfinished ? $finishedLater : null,
                puzzlingTeam: $teamResolver->resolve($group),
                secondsSpent: $unfinished ? $secondsSpent : null,
            );
            $this->entityManager->persist($time);
            $ids[] = $time->id->toString();
        }

        $this->entityManager->flush();

        return $ids;
    }

    /**
     * What saving runs after a result: the puzzle statistics and the wish list (PuzzleSolved), XP for every member;
     * the Players page's numbers come from their cron. Every step is idempotent, so it runs on the unchanged data first.
     *
     * @param list<string> $addedIds
     */
    private function recalculate(array $addedIds): void
    {
        $statistics = self::getContainer()->get(RecalculatePuzzleStatisticsOnSolvingTimeChange::class);
        $wishList = self::getContainer()->get(RemoveFromWishListWhenPuzzleSolved::class);
        $xp = self::getContainer()->get(XpChainRecomputer::class);

        // The fixtures carry no XP ledger: the whole history of every person first, so the award after a save has a
        // ledger to keep (it rebuilds the puzzle's and the week's solves)
        if ($addedIds === []) {
            foreach ([self::REGULAR, self::PARTNER, self::BORROWER] as $playerId) {
                self::getContainer()->get(XpRecomputer::class)->recomputeForPlayer($playerId);
            }
        }

        foreach ($addedIds as $id) {
            $time = $this->entityManager->find(PuzzleSolvingTime::class, $id);
            self::assertNotNull($time);
            $wishList(new PuzzleSolved($time->id, $time->puzzle->id));
            $xp->awardForNewSolve($id);
        }

        foreach (self::PUZZLES as $puzzleId) {
            $statistics(new PuzzleSolved(Uuid::uuid7(), Uuid::fromString($puzzleId)));
        }

        $this->entityManager->flush();
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        self::getContainer()->get(GetUserPuzzleStatuses::class)->reset();
        self::getContainer()->get(GetPlayerSolvedPuzzles::class)->reset();
        self::getContainer()->get(GetRanking::class)->reset();
    }

    /**
     * @return array<mixed>
     */
    private function capture(): array
    {
        $container = self::getContainer();
        $people = [self::REGULAR, self::PARTNER, self::BORROWER];
        $month = (int) TestClock::now()->format('n');
        $year = (int) TestClock::now()->format('Y');

        $getPlayerStatistics = $container->get(GetPlayerStatistics::class);
        $getStatistics = $container->get(GetStatistics::class);
        $getSolvedPuzzles = $container->get(GetPlayerSolvedPuzzles::class);
        $getStatsSnapshot = $container->get(GetPlayerStatsSnapshot::class);
        $getMostActive = $container->get(GetMostActivePlayers::class);
        $getMostSolved = $container->get(GetMostSolvedPuzzles::class);
        $getPuzzleSolvers = $container->get(GetPuzzleSolvers::class);
        $computeStatistics = $container->get(ComputeStatistics::class);

        $capture = [
            'statistics' => [
                'global' => self::normalize($getStatistics->globally()),
                'month' => self::normalize($getStatistics->globallyInMonth($month, $year)),
            ],
            'mostSolved' => self::normalize($getMostSolved->top(50)),
            'mostSolvedInMonth' => self::normalize($getMostSolved->topInMonth(50, $month, $year)),
            'mostActiveSolo' => self::byPlayer(self::normalize($getMostActive->mostActiveSoloPlayers(100))),
            'mostActiveSoloInMonth' => self::byPlayer(self::normalize($getMostActive->mostActiveSoloPlayersInMonth(100, $month, $year))),
            'puzzleStatistics' => $this->rows('SELECT * FROM puzzle_statistics WHERE puzzle_id IN (:puzzleIds) ORDER BY puzzle_id', ['puzzleIds' => self::PUZZLES]),
            'wishList' => $this->rows('SELECT player_id, puzzle_id FROM wish_list_item WHERE player_id IN (:people) ORDER BY player_id, puzzle_id', ['people' => $people]),
            'xpEntries' => $this->rows('SELECT player_id, solving_time_id, reason, amount FROM xp_entry WHERE player_id IN (:people) ORDER BY player_id, solving_time_id, reason, amount', ['people' => $people]),
            'badges' => $this->rows('SELECT player_id, type, tier FROM badge WHERE player_id IN (:people) ORDER BY player_id, type, tier', ['people' => $people]),
            'puzzlesMoments' => $this->rows("SELECT player_id, type, dedupe_key, solving_time_id FROM player_moment WHERE player_id IN (:people) AND dedupe_key NOT LIKE 'pieces:%' ORDER BY player_id, dedupe_key", ['people' => $people]),
            'userSolvedPuzzles' => self::sorted($container->get(GetUserSolvedPuzzles::class)->byUserId(PlayerFixture::PLAYER_REGULAR_USER_ID)),
            'userPuzzleStatuses' => self::normalize($container->get(GetUserPuzzleStatuses::class)->byPlayerId(self::REGULAR)),
            'unsolved' => self::normalize($container->get(GetUnsolvedPuzzles::class)->byPlayerId(self::REGULAR)),
            'unsolvedCount' => $container->get(GetUnsolvedPuzzles::class)->countByPlayerId(self::REGULAR),
            'borrowedUnsolved' => self::normalize($container->get(GetBorrowedPuzzles::class)->unsolvedByHolderId(self::BORROWER)),
            'borrowedUnsolvedCount' => $container->get(GetBorrowedPuzzles::class)->countUnsolvedByHolderId(self::BORROWER),
            'chartSoloCounts' => self::normalize($container->get(GetPlayerChartData::class)->soloCounts(self::REGULAR, false)),
            'playerPuzzleTimes' => self::normalize($container->get(GetPlayerPuzzleTimes::class)->forPuzzles(self::REGULAR, self::PUZZLES)),
            'playerPuzzleSolves' => self::normalize($container->get(GetPlayerPuzzleSolves::class)->forPuzzles(self::REGULAR, self::PUZZLES)),
            'ranking' => self::normalize($container->get(GetRanking::class)->allForPlayer(self::REGULAR)),
            'activityCalendar' => [
                self::REGULAR => self::normalize($container->get(GetPlayerActivityCalendar::class)->perDayInMonth(self::REGULAR, $year, $month)),
            ],
            'activityBuckets' => [
                'dow' => $container->get(GetPlayerActivityCalendar::class)->dayOfWeekBucketsInMonth(self::REGULAR, $year, $month),
                'hour' => $container->get(GetPlayerActivityCalendar::class)->hourOfDayBucketsInMonth(self::REGULAR, $year, $month),
            ],
        ];

        foreach (self::PUZZLES as $puzzleId) {
            $capture['puzzleSolvers'][$puzzleId] = [
                'solo' => self::normalize($getPuzzleSolvers->soloByPuzzleId($puzzleId)),
                'duo' => self::normalize($getPuzzleSolvers->duoByPuzzleId($puzzleId)),
                'team' => self::normalize($getPuzzleSolvers->teamByPuzzleId($puzzleId)),
                'relax' => $getPuzzleSolvers->relaxCountsByPuzzleId($puzzleId),
            ];
        }

        foreach ($people as $playerId) {
            $capture['playerStatistics'][$playerId] = [
                'solo' => self::normalize($getPlayerStatistics->solo($playerId)),
                'duo' => self::normalize($getPlayerStatistics->duo($playerId)),
                'team' => self::normalize($getPlayerStatistics->team($playerId)),
            ];
            $capture['solvedPuzzlesCount'][$playerId] = $getSolvedPuzzles->countByPlayerId($playerId);
            $capture['solvedPuzzlesList'][$playerId] = self::normalize($getSolvedPuzzles->allByPlayerId($playerId));
            $capture['statsSnapshot'][$playerId] = self::normalize($getStatsSnapshot->forPlayer($playerId));
            $capture['communityPlayerStats'][$playerId] = $this->rows(
                'SELECT * FROM community_player_stats WHERE player_id = :playerId',
                ['playerId' => $playerId],
            )[0] ?? [];
            unset($capture['communityPlayerStats'][$playerId]['computed_at']);
        }

        // "Solved N×" of the results REGULAR already had (the profile list itself gains the new rows - history)
        $solvedTimes = [];

        foreach ($getSolvedPuzzles->soloByPlayerId(self::REGULAR) as $result) {
            if ($result->piecesPlaced === null && $result->time !== null) {
                $solvedTimes[$result->timeId] = $result->solvedTimes;
            }
        }

        ksort($solvedTimes);
        $capture['solvedTimes'] = $solvedTimes;

        [$overall, $solo, $duo, $team] = $computeStatistics->forPlayer(
            self::REGULAR,
            new DateTimeImmutable('2000-01-01'),
            TestClock::now()->modify('+1 day'),
            false,
        );
        $capture['profileStatistics'] = [
            self::REGULAR => [
                'overall' => self::normalize($overall),
                'solo' => self::normalize($solo),
                'duo' => self::normalize($duo),
                'team' => self::normalize($team),
            ],
        ];

        $normalized = self::normalize($capture);
        self::assertIsArray($normalized);

        return $normalized;
    }

    private function player(string $id): Player
    {
        $player = $this->entityManager->find(Player::class, $id);
        self::assertNotNull($player);

        return $player;
    }

    private function puzzle(string $id): Puzzle
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $id);
        self::assertNotNull($puzzle);

        return $puzzle;
    }

    private static function puzzler(Player $player): Puzzler
    {
        return new Puzzler(
            playerId: $player->id->toString(),
            playerName: $player->name,
            playerCode: $player->code,
            playerCountry: null,
            isPrivate: false,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        $types = [];

        foreach ($parameters as $name => $value) {
            if (is_array($value)) {
                $types[$name] = ArrayParameterType::STRING;
            }
        }

        return $this->database->fetchAllAssociative($sql, $parameters, $types);
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param mixed $rows a normalized list of rows with a playerId
     * @return array<string, mixed>
     */
    private static function byPlayer(mixed $rows): array
    {
        self::assertIsArray($rows);
        $byPlayer = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['playerId']);
            $byPlayer[$row['playerId']] = $row;
        }

        ksort($byPlayer);

        return $byPlayer;
    }

    /**
     * @param array<mixed> $data
     */
    private static function at(array $data, string ...$path): mixed
    {
        $value = $data;

        foreach ($path as $key) {
            if (is_array($value) === false || array_key_exists($key, $value) === false) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function put(array &$data, mixed $value, string ...$path): void
    {
        $key = array_shift($path);
        self::assertNotNull($key);

        if ($path === []) {
            $data[$key] = $value;

            return;
        }

        $child = $data[$key] ?? [];
        self::assertIsArray($child, $key);
        self::put($child, $value, ...$path);
        $data[$key] = $child;
    }

    /**
     * @param array<mixed> $data
     */
    private static function add(array &$data, int $delta, string ...$path): void
    {
        $value = self::at($data, ...$path) ?? 0;
        self::assertIsInt($value, implode(' / ', $path));
        self::put($data, $value + $delta, ...$path);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $value = array_map(self::normalize(...), $value);

            // Keyed by id or day: the order is the query's, not a meaning; a list of ids neither
            if (array_is_list($value) === false) {
                ksort($value);
            } elseif (array_filter($value, static fn (mixed $item): bool => is_scalar($item) === false) === []) {
                sort($value);
            }
        }

        return $value;
    }
}
