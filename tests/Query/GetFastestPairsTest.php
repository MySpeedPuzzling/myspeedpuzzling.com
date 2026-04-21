<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFastestPairsTest extends KernelTestCase
{
    private GetFastestPairs $query;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetFastestPairs::class);

        /** @var PlayerRepository $playerRepository */
        $playerRepository = $container->get(PlayerRepository::class);
        $this->playerRepository = $playerRepository;
    }

    public function testPerPiecesCountReturnsDuoTimes(): void
    {
        // 1000 piece puzzles have duo times in fixtures (TIME_12, TIME_41)
        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertNotEmpty($results);

        foreach ($results as $result) {
            self::assertSame(1000, $result->piecesCount);
        }
    }

    public function testPerPiecesCountReturnsEmptyForNonExistentPiecesCount(): void
    {
        $results = $this->query->perPiecesCount(42, 10, null);

        self::assertEmpty($results);
    }

    public function testPerPiecesCountRespectsLimit(): void
    {
        $results = $this->query->perPiecesCount(1000, 1, null);

        self::assertLessThanOrEqual(1, count($results));
    }

    public function testMixedTeamWithOnePublicMemberIsShown(): void
    {
        // Both fixture pairs are PLAYER_REGULAR (public) + PLAYER_PRIVATE (private):
        // bool_or(p.is_private = false) is true, so the team must appear.
        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertNotEmpty($results);
    }

    public function testFullyPrivateTeamIsHidden(): void
    {
        // Both fixture pair teams are [PLAYER_REGULAR, PLAYER_PRIVATE]. Marking
        // PLAYER_REGULAR as private leaves no public member, so the bool_or HAVING
        // clause must drop both pair entries.
        $regular = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $regular->changeProfileVisibility(isPrivate: true);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertEmpty($results);
    }
}
