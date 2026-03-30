<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerEloRanking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerEloRankingOptOutTest extends KernelTestCase
{
    private GetPlayerEloRanking $query;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPlayerEloRanking $query */
        $query = $container->get(GetPlayerEloRanking::class);
        $this->query = $query;

        /** @var PlayerRepository $playerRepository */
        $playerRepository = $container->get(PlayerRepository::class);
        $this->playerRepository = $playerRepository;
    }

    public function testOptedOutPlayerExcludedFromRanking(): void
    {
        $entriesBefore = $this->query->ranking(500);
        $countBefore = $this->query->totalCount(500);

        if ($countBefore === 0) {
            self::markTestSkipped('No ELO entries in fixtures');
        }

        $rankedPlayerId = $entriesBefore[0]->playerId;

        $player = $this->playerRepository->get($rankedPlayerId);
        $player->changeRankingOptedOut(true);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $entriesAfter = $this->query->ranking(500);
        $countAfter = $this->query->totalCount(500);

        self::assertSame($countBefore - 1, $countAfter);

        $playerIds = array_map(static fn ($e) => $e->playerId, $entriesAfter);
        self::assertNotContains($rankedPlayerId, $playerIds);
    }

    public function testOptedOutPlayerPositionReturnsNull(): void
    {
        $entriesBefore = $this->query->ranking(500);

        if (count($entriesBefore) === 0) {
            self::markTestSkipped('No ELO entries in fixtures');
        }

        $rankedPlayerId = $entriesBefore[0]->playerId;

        $player = $this->playerRepository->get($rankedPlayerId);
        $player->changeRankingOptedOut(true);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $position = $this->query->playerPosition($rankedPlayerId, 500);

        self::assertNull($position);
    }

    public function testOptedOutPlayerRanksAreContiguous(): void
    {
        $entries = $this->query->ranking(500);

        if (count($entries) < 3) {
            self::markTestSkipped('Need at least 3 ranked players');
        }

        $middleEntry = $entries[1];

        $player = $this->playerRepository->get($middleEntry->playerId);
        $player->changeRankingOptedOut(true);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $entriesAfter = $this->query->ranking(500);

        $ranks = array_map(static fn ($e) => $e->rank, $entriesAfter);

        for ($i = 0; $i < count($ranks) - 1; $i++) {
            self::assertLessThanOrEqual($ranks[$i + 1], $ranks[$i]);
        }

        if (count($ranks) > 0) {
            self::assertSame(1, $ranks[0]);
        }
    }

    public function testOptedOutPlayerStillHasAllForPlayerData(): void
    {
        $entriesBefore = $this->query->ranking(500);

        if (count($entriesBefore) === 0) {
            self::markTestSkipped('No ELO entries in fixtures');
        }

        $rankedPlayerId = $entriesBefore[0]->playerId;

        $dataBefore = $this->query->allForPlayer($rankedPlayerId);

        $player = $this->playerRepository->get($rankedPlayerId);
        $player->changeRankingOptedOut(true);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $dataAfter = $this->query->allForPlayer($rankedPlayerId);

        self::assertCount(count($dataBefore), $dataAfter);
    }
}
