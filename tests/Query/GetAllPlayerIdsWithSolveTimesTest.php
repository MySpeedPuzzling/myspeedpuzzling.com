<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetAllPlayerIdsWithSolveTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAllPlayerIdsWithSolveTimesTest extends KernelTestCase
{
    private GetAllPlayerIdsWithSolveTimes $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetAllPlayerIdsWithSolveTimes::class);
    }

    public function testReturnsNonEmptyList(): void
    {
        $ids = $this->query->execute();

        self::assertNotEmpty($ids);
    }

    public function testAllIdsAreValidUuids(): void
    {
        $ids = $this->query->execute();

        foreach ($ids as $id) {
            self::assertTrue(Uuid::isValid($id), "Expected a valid UUID, got: $id");
        }
    }

    public function testContainsKnownFixturePlayersWithSolveTimes(): void
    {
        $ids = $this->query->execute();

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $ids);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $ids);
        self::assertContains(PlayerFixture::PLAYER_PRIVATE, $ids);
    }

    public function testContainsNoDuplicates(): void
    {
        $ids = $this->query->execute();

        self::assertCount(count(array_unique($ids)), $ids);
    }
}
