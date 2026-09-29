<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetBrandDirectory;
use SpeedPuzzling\Web\Query\GetBrandHub;
use SpeedPuzzling\Web\Results\BrandDirectoryEntry;
use SpeedPuzzling\Web\Tests\CatalogueTestData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetBrandDirectoryTest extends KernelTestCase
{
    use CatalogueTestData;

    private GetBrandDirectory $getBrandDirectory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getBrandDirectory = self::getContainer()->get(GetBrandDirectory::class);
    }

    public function testListsExactlyTheBrandsWhoseHubIsIndexable(): void
    {
        $entries = $this->getBrandDirectory->indexableBrands();
        $slugs = array_map(static fn (BrandDirectoryEntry $entry): string => $entry->slug, $entries);

        // Unknown Brand is unapproved and has a single unsolved puzzle
        self::assertSame(['ravensburger', 'trefl'], $slugs);

        $getBrandHub = self::getContainer()->get(GetBrandHub::class);

        foreach ($entries as $entry) {
            $hub = $getBrandHub->bySlug($entry->slug);

            self::assertTrue($hub->isIndexable(), $entry->slug);
            self::assertSame($hub->brandName, $entry->brandName);
            self::assertSame($hub->puzzlesCount, $entry->puzzlesCount, 'Same puzzle count as the hub shows');
            self::assertSame($hub->solvesCount, $entry->solvesCount, 'Same solves count as the hub shows');
            self::assertSame(strtoupper($entry->brandName[0]), $entry->letter);
        }

        self::assertFalse($getBrandHub->bySlug('unknown-brand')->isIndexable());
    }

    public function testListsTheIndexableBrandPiecesPages(): void
    {
        // Fixtures: Ravensburger 500 (8 visible puzzles) and 1000 (6) qualify;
        // Ravensburger 300 (1 puzzle) and all of Trefl (at most 2 per count) do not
        self::assertSame([
            ['slug' => 'ravensburger', 'pieces' => 500],
            ['slug' => 'ravensburger', 'pieces' => 1000],
        ], $this->getBrandDirectory->indexableBrandPiecesPages());

        // 6 Trefl 500-piece puzzles: enough puzzles, and the combination has a solve
        self::addFillerPuzzles(self::getContainer(), ManufacturerFixture::MANUFACTURER_TREFL, 500, 4);

        self::assertContains(['slug' => 'trefl', 'pieces' => 500], $this->getBrandDirectory->indexableBrandPiecesPages());

        // Enough puzzles but never solved
        self::addFillerPuzzles(self::getContainer(), ManufacturerFixture::MANUFACTURER_TREFL, 750, 6);

        self::assertNotContains(['slug' => 'trefl', 'pieces' => 750], $this->getBrandDirectory->indexableBrandPiecesPages());

        // Brand hub not indexable (unapproved brand): never listed
        self::addFillerPuzzles(self::getContainer(), ManufacturerFixture::MANUFACTURER_UNAPPROVED, 1000, 10);

        foreach ($this->getBrandDirectory->indexableBrandPiecesPages() as $page) {
            self::assertNotSame('unknown-brand', $page['slug']);
        }
    }

    public function testBrandHubAgreesWithTheSitemapOnPiecesPages(): void
    {
        $hub = self::getContainer()->get(GetBrandHub::class)->bySlug('ravensburger');

        $indexable = [];
        foreach ($hub->indexablePiecesPages() as $piecesPage) {
            $indexable[] = ['slug' => 'ravensburger', 'pieces' => $piecesPage->piecesCount];
        }

        self::assertSame($indexable, array_values(array_filter(
            $this->getBrandDirectory->indexableBrandPiecesPages(),
            static fn (array $page): bool => $page['slug'] === 'ravensburger',
        )));
    }
}
