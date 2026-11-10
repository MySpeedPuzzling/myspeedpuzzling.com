<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Data the fixtures lack, for the tests that compare the result detail and the activity feed statements with their
 * previous SQL in every column: skill tiers, a team of three with a guest (two attempts), a private player's pair
 * with a guest, a suspicious time, an edition and a series pick, a hidden picture, a puzzle without a brand and a
 * block of a player who tracked group times.
 */
readonly final class ResultQueriesParityScenario
{
    public const string TEAM_PUZZLE = PuzzleFixture::PUZZLE_1000_04;
    public const string PRIVATE_PAIR_PUZZLE = PuzzleFixture::PUZZLE_1000_05;
    public const string HIDDEN_IMAGE_PUZZLE = PuzzleFixture::PUZZLE_500_01;
    public const string BRANDLESS_PUZZLE = PuzzleFixture::PUZZLE_1500_02;

    /**
     * @return array{team_times: list<string>, private_pair_time: string, series_pick_time: string}
     */
    public static function seed(ContainerInterface $container): array
    {
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $database = $container->get(Connection::class);
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $bus = $container->get(MessageBusInterface::class);
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $now = $container->get(ClockInterface::class)->now();

        self::skill($database, PlayerFixture::PLAYER_REGULAR, 3);
        self::skill($database, PlayerFixture::PLAYER_PRIVATE, 5);

        // A team of three with a guest, twice; then a private player's pair with a guest
        $teamTimes = [
            self::add($bus, PlayerFixture::PLAYER_REGULAR_USER_ID, self::TEAM_PUZZLE, '01:40:00', ['#admin', 'Grandma'], $now->modify('-3 days')),
            self::add($bus, PlayerFixture::PLAYER_REGULAR_USER_ID, self::TEAM_PUZZLE, '01:30:00', ['#admin', 'Grandma'], $now->modify('-1 day')),
        ];
        $privatePairTime = self::add($bus, PlayerFixture::PLAYER_PRIVATE_USER_ID, self::PRIVATE_PAIR_PUZZLE, '02:10:00', ['Grandma'], $now->modify('-2 days'));

        // Times added now were all tracked at the same moment - spread them like real ones
        foreach ([...$teamTimes, $privatePairTime] as $index => $timeId) {
            $database->executeStatement(
                'UPDATE puzzle_solving_time SET tracked_at = tracked_at - make_interval(mins => :minutes) WHERE id = :id',
                ['minutes' => ($index + 1) * 7, 'id' => $timeId],
                ['minutes' => ParameterType::INTEGER],
            );
        }

        $database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_06]);

        // An edition of a series, and a series pick (series-level, no edition)
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :id',
            ['competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'id' => PuzzleSolvingTimeFixture::TIME_36],
        );
        $seriesPickTime = PuzzleSolvingTimeFixture::TIME_07;
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = NULL, competition_series_id = :seriesId WHERE id = :id',
            ['seriesId' => CompetitionSeriesFixture::SERIES_EJJ, 'id' => $seriesPickTime],
        );

        $database->executeStatement(
            'UPDATE puzzle SET hide_image_until = :until WHERE id = :id',
            ['until' => $now->modify('+1 day')->format('Y-m-d H:i:s'), 'id' => self::HIDDEN_IMAGE_PUZZLE],
        );
        $database->executeStatement('UPDATE puzzle SET manufacturer_id = NULL WHERE id = :id', ['id' => self::BRANDLESS_PUZZLE]);

        // PLAYER_ADMIN hides PLAYER_REGULAR, who tracked group times (the fixtures: PLAYER_REGULAR hides PLAYER_PRIVATE)
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_ADMIN, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );

        $container->get('doctrine')->getManager()->clear();

        return [
            'team_times' => $teamTimes,
            'private_pair_time' => $privatePairTime,
            'series_pick_time' => $seriesPickTime,
        ];
    }

    /**
     * Guests, a player hiding somebody, a private player, a player hiding a group tracker, a player on a private
     * player's allow list
     *
     * @return list<null|string>
     */
    public static function viewers(): array
    {
        return [
            null,
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_PRIVATE,
            PlayerFixture::PLAYER_ADMIN,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        ];
    }

    public static function skill(Connection $database, string $playerId, int $tier, int $pieces = 500): void
    {
        $database->executeStatement(
            "INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at)
            VALUES (:id, :playerId, :pieces, 1.0, :tier, 50.0, 'high', 10, " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'pieces' => $pieces, 'tier' => $tier],
            ['pieces' => ParameterType::INTEGER, 'tier' => ParameterType::INTEGER],
        );
    }

    /**
     * @param list<string> $groupPlayers
     */
    private static function add(
        MessageBusInterface $bus,
        string $userId,
        string $puzzleId,
        string $time,
        array $groupPlayers,
        DateTimeImmutable $finishedAt,
    ): string {
        $timeId = Uuid::uuid7();

        $bus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: 'parity ' . $time,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: $finishedAt,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }
}
