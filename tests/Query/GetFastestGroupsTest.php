<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetFastestGroups;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFastestGroupsTest extends KernelTestCase
{
    private GetFastestGroups $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetFastestGroups::class);
    }

    public function testPerPiecesCountDoesNotFail(): void
    {
        // No team (3+ puzzlers) fixtures exist, but the query should execute without SQL error
        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertEmpty($results);
    }

    public function testPerPiecesCountReturnsEmptyForNonExistentPiecesCount(): void
    {
        $results = $this->query->perPiecesCount(42, 10, null);

        self::assertEmpty($results);
    }
}
