<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Export;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\Export\ExportDocument;
use SpeedPuzzling\Web\Results\Export\ExportSection;
use SpeedPuzzling\Web\Services\Export\PuzzleLibraryExportBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PuzzleLibraryExportBuilderTest extends KernelTestCase
{
    public function testEverySectionIsThereInOrderAndEveryRowHasEveryColumn(): void
    {
        $document = $this->build(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame(
            ['collections', 'collection_items', 'wishlist', 'lent_out', 'borrowed', 'lend_borrow_history', 'sell_swap', 'sell_swap_events', 'sold_swapped'],
            array_map(static fn (ExportSection $section): string => $section->name, $document->sections),
        );

        foreach ($document->sections as $section) {
            foreach ($section->rows as $row) {
                self::assertSame($section->columns, array_keys($row), sprintf('Section %s', $section->name));
            }
        }

        self::assertSame(PuzzleLibraryExportBuilder::FORMAT_VERSION, $document->about['format_version']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $document->about['player_id']);
        self::assertSame('GBP', $document->about['sell_swap_currency']);
    }

    public function testCollectionsStartWithTheSystemCollectionAndCountTheirItems(): void
    {
        $document = $this->build(PlayerFixture::PLAYER_WITH_STRIPE);
        $collections = $this->section($document, 'collections')->rows;

        self::assertSame(Collection::SYSTEM_ID, $collections[0]['collection_id']);
        self::assertTrue($collections[0]['is_system_collection']);

        // Item counts match what the collection pages count
        $countItems = self::getContainer()->get(GetCollectionItems::class);
        $byId = array_column($collections, 'item_count', 'collection_id');
        self::assertCount(3, $byId);
        foreach ($byId as $collectionId => $itemCount) {
            $expected = $countItems->countByCollectionAndPlayer(
                $collectionId === Collection::SYSTEM_ID ? null : (string) $collectionId,
                PlayerFixture::PLAYER_WITH_STRIPE,
            );
            self::assertSame($expected, $itemCount, sprintf('Collection %s', $collectionId));
        }

        $items = $this->section($document, 'collection_items')->rows;
        self::assertCount(array_sum($byId), $items);

        // PUZZLE_500_02 is in the system collection and both custom ones - a row for each
        $inCollections = array_column(
            array_filter($items, static fn (array $row): bool => $row['puzzle_id'] === PuzzleFixture::PUZZLE_500_02),
            'collection_id',
        );
        sort($inCollections);
        $expected = [Collection::SYSTEM_ID, CollectionFixture::COLLECTION_PUBLIC, CollectionFixture::COLLECTION_STRIPE_TREFL];
        sort($expected);
        self::assertSame($expected, $inCollections);
    }

    public function testPrivateCollectionsAreOwnDataAndExportedForANonMemberToo(): void
    {
        $document = $this->build(PlayerFixture::PLAYER_REGULAR);

        $collectionIds = array_column($this->section($document, 'collections')->rows, 'collection_id');
        self::assertContains(CollectionFixture::COLLECTION_PRIVATE, $collectionIds);
    }

    public function testLendingIsSeenFromThePlayersSide(): void
    {
        $document = $this->build(PlayerFixture::PLAYER_WITH_STRIPE);

        $lentOut = $this->section($document, 'lent_out')->rows;
        $holders = array_column($lentOut, 'current_holder_name');
        self::assertContains('Jane Doe', $holders, 'Somebody without an account keeps the typed name.');
        self::assertContains('John Doe', $holders);

        $janeDoe = array_values(array_filter($lentOut, static fn (array $row): bool => $row['current_holder_name'] === 'Jane Doe'))[0];
        self::assertFalse($janeDoe['current_holder_is_registered']);
        self::assertNull($janeDoe['current_holder_code']);

        $borrowedPuzzles = array_column($this->section($document, 'borrowed')->rows, 'owner_name', 'puzzle_id');
        self::assertSame('John Doe', $borrowedPuzzles[PuzzleFixture::PUZZLE_1500_02] ?? null);
        self::assertSame('John Doe', $borrowedPuzzles[PuzzleFixture::PUZZLE_3000] ?? null);

        $roles = array_unique(array_column($this->section($document, 'lend_borrow_history')->rows, 'my_role'));
        self::assertContains('owner,from', $roles);
        self::assertContains('to', $roles);
    }

    public function testSellSwapCarriesTheListCurrencyAndEveryEventMark(): void
    {
        $document = $this->build(PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ($this->section($document, 'sell_swap')->rows as $row) {
            self::assertSame($row['price'] === null ? null : 'GBP', $row['currency']);
        }

        $shown = array_column($this->section($document, 'sell_swap_events')->rows, 'currently_shown', 'sell_swap_item_id');
        self::assertTrue($shown[SellSwapListItemFixture::SELLSWAP_01]);
        self::assertTrue($shown[SellSwapListItemFixture::SELLSWAP_02]);
        self::assertFalse($shown[SellSwapListItemFixture::SELLSWAP_07], 'A past event stays in the export, just not shown on the site.');
    }

    public function testACustomCurrencyIsTheOneTheSellerTypedIn(): void
    {
        $document = $this->build(PlayerFixture::PLAYER_ADMIN);

        self::assertSame('Kč', $document->about['sell_swap_currency']);
    }

    public function testOwnResultsCountPairsTheyAreAMemberOf(): void
    {
        $document = $this->build(PlayerFixture::PLAYER_REGULAR);

        // PUZZLE_1000_01 is in PLAYER_REGULAR's system collection; TIME_12 is a pair result on it
        $rows = array_values(array_filter(
            $this->section($document, 'collection_items')->rows,
            static fn (array $row): bool => $row['puzzle_id'] === PuzzleFixture::PUZZLE_1000_01,
        ));

        self::assertNotEmpty($rows);
        self::assertIsInt($rows[0]['my_solved_count']);
        self::assertGreaterThanOrEqual(1, $rows[0]['my_solved_count']);
        self::assertNotNull($rows[0]['my_first_solved_at']);
        self::assertStringStartsWith('http', (string) $rows[0]['puzzle_url']);
    }

    public function testDifficultyIsForMembersOnly(): void
    {
        $nonMember = $this->build(PlayerFixture::PLAYER_REGULAR);

        foreach ($nonMember->sections as $section) {
            foreach ($section->rows as $row) {
                if (array_key_exists('difficulty_tier', $row)) {
                    self::assertNull($row['difficulty_tier']);
                }
            }
        }

        $member = $this->build(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertContains('difficulty_tier', $this->section($member, 'collection_items')->columns);
    }

    private function build(string $playerId): ExportDocument
    {
        self::bootKernel();
        $container = self::getContainer();

        $player = $container->get(GetPlayerProfile::class)->byId($playerId);

        return $container->get(PuzzleLibraryExportBuilder::class)->build($player);
    }

    private function section(ExportDocument $document, string $name): ExportSection
    {
        foreach ($document->sections as $section) {
            if ($section->name === $name) {
                return $section;
            }
        }

        self::fail(sprintf('No section %s', $name));
    }
}
