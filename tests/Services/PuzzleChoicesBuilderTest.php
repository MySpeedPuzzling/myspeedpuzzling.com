<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\BrandCodeCheckDigit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PuzzleChoicesBuilderTest extends KernelTestCase
{
    public function testABrandCodeIsSearchableWithTheCheckDigitButShownAsStored(): void
    {
        $options = $this->ravensburgerOptions();

        // PUZZLE_1000_05: 17481 + 4005556174812 - the box prints "17481 2"
        $option = $options[PuzzleFixture::PUZZLE_1000_05];
        self::assertSame("4005556174812, 4005556197484\n17481, 19748-2\n174812", $option['codes']);
        // Shown as stored - the alias is for searching only
        self::assertStringContainsString('<small class="text-muted">17481, 19748-2</small>', $option['text']);
    }

    /**
     * The picker filters in the browser and builds its aliases from the same columns the code key is built from - every
     * alias it searches must be a `c:` line of the stored key, or the picker and the search would disagree.
     */
    public function testThePickerSearchesTheAliasesOfTheStoredKey(): void
    {
        $database = self::getContainer()->get(Connection::class);
        $checked = 0;

        foreach ($this->ravensburgerOptions() as $puzzleId => $option) {
            /** @var array{ean: null|string, identification_number: null|string, search_codes: null|string} $row */
            $row = $database->fetchAssociative('SELECT ean, identification_number, search_codes FROM puzzle WHERE id = :id', ['id' => $puzzleId]);

            foreach (BrandCodeCheckDigit::aliases($row['ean'], $row['identification_number']) as $alias) {
                self::assertStringContainsString($alias, $option['codes']);
                self::assertStringContainsString("\nc:{$alias}\n", (string) $row['search_codes']);
                $checked++;
            }
        }

        self::assertGreaterThan(0, $checked, 'the fixtures hold no alias - the test checks nothing');
    }

    /**
     * @return array<string, array{value: string, text: string, name: string, names: string, codes: string, piecesCount: int}>
     */
    private function ravensburgerOptions(): array
    {
        $puzzles = self::getContainer()->get(SearchPuzzle::class)->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $options = [];

        foreach (self::getContainer()->get(PuzzleChoicesBuilder::class)->build($puzzles, 'en') as $option) {
            $options[$option['value']] = $option;
        }

        return $options;
    }
}
