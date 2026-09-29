<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCatalogueNumbers;
use SpeedPuzzling\Web\Services\CatalogueNumbersProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

final class CatalogueNumbersProviderTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->cache()->delete(CatalogueNumbersProvider::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        // The cache is not rolled back with the database - leave no snapshot of the edited data behind
        $this->cache()->delete(CatalogueNumbersProvider::CACHE_KEY);

        parent::tearDown();
    }

    public function testServesOneSnapshotUntilItExpires(): void
    {
        $provider = self::getContainer()->get(CatalogueNumbersProvider::class);
        $query = self::getContainer()->get(GetCatalogueNumbers::class);

        $snapshot = $provider->numbers();
        self::assertEquals($query->current(), $snapshot);

        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = '2099-12-31 00:00:00' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_300],
        );

        // The database moved on, the pages keep quoting the same numbers
        self::assertNotEquals($query->current(), $snapshot);
        self::assertEquals($snapshot, $provider->numbers());
    }

    private function cache(): CacheInterface
    {
        return self::getContainer()->get(CacheInterface::class);
    }
}
