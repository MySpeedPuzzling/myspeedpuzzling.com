<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPlayerBestSoloTimes;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerBestSoloTimesTest extends KernelTestCase
{
    private GetPlayerBestSoloTimes $query;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var GetPlayerBestSoloTimes $query */
        $query = self::getContainer()->get(GetPlayerBestSoloTimes::class);
        $this->query = $query;

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
    }

    /**
     * The activity feed's "My time" used to come from GetRanking::allForPlayer():
     * the same puzzles and the same seconds must come out.
     */
    public function testMatchesTheTimeOfTheFullRanking(): void
    {
        /** @var GetRanking $getRanking */
        $getRanking = self::getContainer()->get(GetRanking::class);

        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->connection->fetchFirstColumn('SELECT id FROM puzzle');
        /** @var list<string> $playerIds */
        $playerIds = $this->connection->fetchFirstColumn('SELECT id FROM player');

        $timesSeen = 0;

        foreach ($playerIds as $playerId) {
            $expected = array_map(
                static fn (PlayerRanking $ranking): int => $ranking->time,
                $getRanking->allForPlayer($playerId),
            );
            $actual = $this->query->forPuzzles($playerId, $puzzleIds);

            ksort($expected);
            ksort($actual);
            self::assertSame($expected, $actual, sprintf('best solo times of player %s', $playerId));

            $timesSeen += count($actual);
        }

        self::assertGreaterThan(0, $timesSeen);
    }

    public function testOnlyTheAskedPuzzles(): void
    {
        $all = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, $this->allPuzzleIds());
        self::assertGreaterThan(1, count($all));

        $firstPuzzleId = array_key_first($all);
        self::assertIsString($firstPuzzleId);
        self::assertSame([$firstPuzzleId => $all[$firstPuzzleId]], $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [$firstPuzzleId]));
        self::assertSame([], $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, []));
    }

    /**
     * @return list<string>
     */
    private function allPuzzleIds(): array
    {
        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->connection->fetchFirstColumn('SELECT id FROM puzzle');

        return $puzzleIds;
    }
}
