<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetFastestPairs;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFastestPairsTest extends KernelTestCase
{
    private GetFastestPairs $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetFastestPairs::class);
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
}
