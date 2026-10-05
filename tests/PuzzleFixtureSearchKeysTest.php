<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The search keys of every puzzle in the test database are what the entity builds from its names and codes - so the
 * search tests run on the keys production has, and a fixture written past the entity shows up here.
 */
final class PuzzleFixtureSearchKeysTest extends KernelTestCase
{
    public function testEveryPuzzleHasTheKeysOfItsNamesAndCodes(): void
    {
        self::bootKernel();

        /** @var list<array{id: string, name: string, alternative_names: string, ean: null|string, identification_number: null|string, search_names: null|string, search_codes: null|string}> $rows */
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT id, name, alternative_names, ean, identification_number, search_names, search_codes FROM puzzle',
        );

        self::assertNotEmpty($rows);

        foreach ($rows as $row) {
            $alternativeNames = PuzzleNames::fromJson($row['alternative_names']);

            self::assertSame(PuzzleSearchKeys::names($row['name'], $alternativeNames), $row['search_names'], $row['id']);
            self::assertSame(PuzzleSearchKeys::codes($row['ean'], $row['identification_number']), $row['search_codes'], $row['id']);
        }
    }

    public function testTheFixtureNamesAndCodesAreInTheKeys(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);

        $keys = static fn (string $puzzleId): array => $database->fetchAssociative(
            'SELECT search_names, search_codes FROM puzzle WHERE id = :id',
            ['id' => $puzzleId],
        ) ?: [];

        self::assertSame(['search_names' => "\npuzzle 7\nkouzelna zahrada\nzauberhafter garten\n", 'search_codes' => null], $keys(PuzzleFixture::PUZZLE_1000_02));
        self::assertSame("\npuzzle 11\nkouzelna zahrada\n", $keys(PuzzleFixture::PUZZLE_300)['search_names'] ?? null);
        self::assertSame("\npuzzle hidden image\n魔法の庭\n", $keys(PuzzleFixture::PUZZLE_HIDDEN_IMAGE)['search_names'] ?? null);
        self::assertSame(
            "\ne:4005556174812\ne:4005556197484\nc:17481\nc:197482\nc:174812\n",
            $keys(PuzzleFixture::PUZZLE_1000_05)['search_codes'] ?? null,
        );
        self::assertSame("\ne:5900511101010\n", $keys(PuzzleFixture::PUZZLE_1500_02)['search_codes'] ?? null);
    }
}
