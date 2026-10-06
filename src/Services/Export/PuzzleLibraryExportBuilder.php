<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Export;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Query\GetCollectionDisplayMode;
use SpeedPuzzling\Web\Query\GetExportableBorrowed;
use SpeedPuzzling\Web\Query\GetExportableCollectionItems;
use SpeedPuzzling\Web\Query\GetExportableCollections;
use SpeedPuzzling\Web\Query\GetExportableLendBorrowHistory;
use SpeedPuzzling\Web\Query\GetExportableLentOut;
use SpeedPuzzling\Web\Query\GetExportablePuzzleSolveSummary;
use SpeedPuzzling\Web\Query\GetExportableSellSwap;
use SpeedPuzzling\Web\Query\GetExportableSellSwapEvents;
use SpeedPuzzling\Web\Query\GetExportableSoldSwapped;
use SpeedPuzzling\Web\Query\GetExportableWishList;
use SpeedPuzzling\Web\Results\Export\ExportableCollection;
use SpeedPuzzling\Web\Results\Export\ExportableCollectionItem;
use SpeedPuzzling\Web\Results\Export\ExportableLendBorrowTransfer;
use SpeedPuzzling\Web\Results\Export\ExportableLentPuzzle;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzleSolveSummary;
use SpeedPuzzling\Web\Results\Export\ExportableSellSwapEvent;
use SpeedPuzzling\Web\Results\Export\ExportableSellSwapItem;
use SpeedPuzzling\Web\Results\Export\ExportableSoldSwappedItem;
use SpeedPuzzling\Web\Results\Export\ExportableWishListItem;
use SpeedPuzzling\Web\Results\Export\ExportDocument;
use SpeedPuzzling\Web\Results\Export\ExportSection;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PuzzleListInsights;
use SpeedPuzzling\Web\Services\ResolvePuzzleListInsights;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The player's whole puzzle library as flat sections (docs/features/data-export.md). One statement per section plus
 * two puzzle-level ones (own results, community count + difficulty), constant in library size - the puzzle facts
 * are merged in PHP so no section re-aggregates results.
 */
readonly final class PuzzleLibraryExportBuilder
{
    public const string ROOT_NAME = 'puzzle_library';

    /**
     * Bump only on a breaking change (a column removed or renamed); added columns are not breaking.
     */
    public const int FORMAT_VERSION = 1;

    public const array PUZZLE_COLUMNS = [
        'puzzle_id',
        'puzzle_name',
        'puzzle_name_language',
        'puzzle_other_names',
        'brand_name',
        'pieces_count',
        'ean',
        'brand_code',
        'puzzle_url',
        'puzzle_image_url',
        'my_solved_count',
        'my_first_solved_at',
        'my_last_solved_at',
        'my_best_solo_seconds',
        'my_best_solo_time',
        'community_solved_count',
        'difficulty_tier',
    ];

    private const array COLLECTION_COLUMNS = ['collection_id', 'is_system_collection', 'name', 'description', 'visibility', 'created_at', 'item_count'];

    public function __construct(
        private GetExportableCollections $getExportableCollections,
        private GetExportableCollectionItems $getExportableCollectionItems,
        private GetExportableWishList $getExportableWishList,
        private GetExportableLentOut $getExportableLentOut,
        private GetExportableBorrowed $getExportableBorrowed,
        private GetExportableLendBorrowHistory $getExportableLendBorrowHistory,
        private GetExportableSellSwap $getExportableSellSwap,
        private GetExportableSellSwapEvents $getExportableSellSwapEvents,
        private GetExportableSoldSwapped $getExportableSoldSwapped,
        private GetExportablePuzzleSolveSummary $getExportablePuzzleSolveSummary,
        private GetCollectionDisplayMode $getCollectionDisplayMode,
        private ResolvePuzzleListInsights $resolvePuzzleListInsights,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    public function build(PlayerProfile $player): ExportDocument
    {
        $playerId = $player->playerId;

        $collections = $this->getExportableCollections->byPlayerId($playerId);
        $collectionItems = $this->getExportableCollectionItems->byPlayerId($playerId);
        $wishList = $this->getExportableWishList->byPlayerId($playerId);
        $lentOut = $this->getExportableLentOut->byPlayerId($playerId);
        $borrowed = $this->getExportableBorrowed->byPlayerId($playerId);
        $history = $this->getExportableLendBorrowHistory->byPlayerId($playerId);
        $sellSwap = $this->getExportableSellSwap->byPlayerId($playerId);
        $sellSwapEvents = $this->getExportableSellSwapEvents->byPlayerId($playerId);
        $soldSwapped = $this->getExportableSoldSwapped->byPlayerId($playerId);

        $puzzleIds = [];
        foreach ([$collectionItems, $wishList, $lentOut, $borrowed, $history, $sellSwap, $soldSwapped] as $items) {
            foreach ($items as $item) {
                if ($item->puzzle !== null) {
                    $puzzleIds[$item->puzzle->puzzleId] = $item->puzzle->puzzleId;
                }
            }
        }
        $puzzleIds = array_values($puzzleIds);

        $solves = $this->getExportablePuzzleSolveSummary->forPuzzles($playerId, $puzzleIds);
        $insights = $this->resolvePuzzleListInsights->forViewer($player, $puzzleIds);

        $puzzleColumns = fn (null|ExportablePuzzle $puzzle): array => $this->puzzleColumns($puzzle, $solves, $insights);
        $systemCollectionName = $this->translator->trans('collections.system_name');
        $currency = $this->listCurrency($player);

        $sections = [
            $this->collectionsSection($player, $collections, $collectionItems, $systemCollectionName),
            new ExportSection(
                name: 'collection_items',
                description: 'Every puzzle in your collections; a puzzle in several collections has a row for each.',
                columns: [...ExportableCollectionItem::COLUMNS, ...self::PUZZLE_COLUMNS],
                rows: array_map(
                    static fn (ExportableCollectionItem $item): array => [...$item->toColumns($systemCollectionName), ...$puzzleColumns($item->puzzle)],
                    $collectionItems,
                ),
            ),
            new ExportSection(
                name: 'wishlist',
                description: 'Puzzles on your wishlist.',
                columns: [...ExportableWishListItem::COLUMNS, ...self::PUZZLE_COLUMNS],
                rows: array_map(
                    static fn (ExportableWishListItem $item): array => [...$item->toColumns(), ...$puzzleColumns($item->puzzle)],
                    $wishList,
                ),
            ),
            new ExportSection(
                name: 'lent_out',
                description: 'Your puzzles you lent out, with who has them now.',
                columns: [...ExportableLentPuzzle::columns('current_holder'), ...self::PUZZLE_COLUMNS],
                rows: array_map(
                    static fn (ExportableLentPuzzle $item): array => [...$item->toColumns('current_holder'), ...$puzzleColumns($item->puzzle)],
                    $lentOut,
                ),
            ),
            new ExportSection(
                name: 'borrowed',
                description: 'Puzzles you borrowed and have now, with their owner.',
                columns: [...ExportableLentPuzzle::columns('owner'), ...self::PUZZLE_COLUMNS],
                rows: array_map(
                    static fn (ExportableLentPuzzle $item): array => [...$item->toColumns('owner'), ...$puzzleColumns($item->puzzle)],
                    $borrowed,
                ),
            ),
            new ExportSection(
                name: 'lend_borrow_history',
                description: 'Every hand-over you took part in: lending, passing on, returning. my_role says whether you were the owner, the one giving or the one receiving.',
                columns: [...ExportableLendBorrowTransfer::COLUMNS, ...self::PUZZLE_COLUMNS],
                rows: array_map(
                    static fn (ExportableLendBorrowTransfer $item): array => [...$item->toColumns(), ...$puzzleColumns($item->puzzle)],
                    $history,
                ),
            ),
            new ExportSection(
                name: 'sell_swap',
                description: 'Your sell/swap list. Prices are in the currency your list uses today.',
                columns: [...ExportableSellSwapItem::COLUMNS, ...self::PUZZLE_COLUMNS],
                rows: array_map(
                    static fn (ExportableSellSwapItem $item): array => [...$item->toColumns($currency), ...$puzzleColumns($item->puzzle)],
                    $sellSwap,
                ),
            ),
            new ExportSection(
                name: 'sell_swap_events',
                description: 'Listings you marked as bringing to an event; currently_shown is false once the event is over or you are no longer going.',
                columns: ExportableSellSwapEvent::COLUMNS,
                rows: array_map(
                    static fn (ExportableSellSwapEvent $item): array => $item->toColumns(),
                    $sellSwapEvents,
                ),
            ),
            new ExportSection(
                name: 'sold_swapped',
                description: 'Puzzles you sold, swapped or gave away. Prices are in whatever currency your list had at the time.',
                columns: [...ExportableSoldSwappedItem::COLUMNS, ...self::PUZZLE_COLUMNS],
                rows: array_map(
                    static fn (ExportableSoldSwappedItem $item): array => [...$item->toColumns(), ...$puzzleColumns($item->puzzle)],
                    $soldSwapped,
                ),
            ),
        ];

        return new ExportDocument(self::ROOT_NAME, $this->about($player, $currency), $sections);
    }

    /**
     * @param list<ExportableCollection> $collections
     * @param list<ExportableCollectionItem> $items
     */
    private function collectionsSection(PlayerProfile $player, array $collections, array $items, string $systemCollectionName): ExportSection
    {
        $itemCounts = [];
        foreach ($items as $item) {
            $key = $item->collectionId ?? Collection::SYSTEM_ID;
            $itemCounts[$key] = ($itemCounts[$key] ?? 0) + 1;
        }

        $rows = [[
            'collection_id' => Collection::SYSTEM_ID,
            'is_system_collection' => true,
            'name' => $systemCollectionName,
            'description' => null,
            'visibility' => $player->puzzleCollectionVisibility->value,
            'created_at' => null,
            'item_count' => $itemCounts[Collection::SYSTEM_ID] ?? 0,
        ]];

        foreach ($collections as $collection) {
            $rows[] = [
                'collection_id' => $collection->collectionId,
                'is_system_collection' => false,
                'name' => $collection->name,
                'description' => $collection->description,
                'visibility' => $collection->visibility->value,
                'created_at' => $collection->createdAt->format('Y-m-d H:i:s'),
                'item_count' => $itemCounts[$collection->collectionId] ?? 0,
            ];
        }

        return new ExportSection(
            name: 'collections',
            description: 'Your collections, your main puzzle collection first.',
            columns: self::COLLECTION_COLUMNS,
            rows: $rows,
        );
    }

    /**
     * @param array<string, ExportablePuzzleSolveSummary> $solves
     * @return array<string, scalar|null>
     */
    private function puzzleColumns(null|ExportablePuzzle $puzzle, array $solves, PuzzleListInsights $insights): array
    {
        if ($puzzle === null) {
            return array_fill_keys(self::PUZZLE_COLUMNS, null);
        }

        $solve = $solves[$puzzle->puzzleId] ?? null;
        $insight = $insights->byPuzzle[$puzzle->puzzleId] ?? null;

        return [
            'puzzle_id' => $puzzle->puzzleId,
            'puzzle_name' => $puzzle->name,
            'puzzle_name_language' => $puzzle->nameLanguage,
            'puzzle_other_names' => self::nullIfEmpty($puzzle->otherNamesText()),
            'brand_name' => $puzzle->brandName,
            'pieces_count' => $puzzle->piecesCount,
            'ean' => self::nullIfEmpty(implode(', ', $puzzle->eans->display())),
            'brand_code' => self::nullIfEmpty(implode(', ', $puzzle->brandCodes->display())),
            'puzzle_url' => $this->urlGenerator->generate('puzzle_detail', ['puzzleId' => $puzzle->puzzleId], UrlGeneratorInterface::ABSOLUTE_URL),
            'puzzle_image_url' => $puzzle->imageUrl,
            'my_solved_count' => $solve->solvedCount ?? 0,
            'my_first_solved_at' => $solve?->firstSolvedAt->format('Y-m-d H:i:s'),
            'my_last_solved_at' => $solve?->lastSolvedAt->format('Y-m-d H:i:s'),
            'my_best_solo_seconds' => $solve?->bestSoloSeconds,
            'my_best_solo_time' => self::formatSeconds($solve?->bestSoloSeconds),
            'community_solved_count' => $insight->solvedTimes ?? 0,
            // Members only - for everyone else ResolvePuzzleListInsights does not even query it
            'difficulty_tier' => $insight?->difficultyTier?->toApiValue(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function about(PlayerProfile $player, null|string $currency): array
    {
        $settings = $player->sellSwapListSettings;

        return [
            'format_version' => self::FORMAT_VERSION,
            'generated_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'player_id' => $player->playerId,
            'player_code' => strtoupper($player->code),
            'player_name' => $player->playerName,
            'puzzle_collection_visibility' => $player->puzzleCollectionVisibility->value,
            'wish_list_visibility' => $player->wishListVisibility->value,
            'unsolved_puzzles_visibility' => $player->unsolvedPuzzlesVisibility->value,
            'solved_puzzles_visibility' => $player->solvedPuzzlesVisibility->value,
            'lend_borrow_list_visibility' => $player->lendBorrowListVisibility->value,
            'collection_display_mode' => $this->getCollectionDisplayMode->forPlayer($player->playerId)->value,
            'sell_swap_description' => $settings?->description,
            'sell_swap_currency' => $currency,
            'sell_swap_shipping_info' => $settings?->shippingInfo,
            'sell_swap_shipping_countries' => $settings !== null ? self::nullIfEmpty(implode(', ', $settings->shippingCountries)) : null,
            'sell_swap_shipping_cost' => $settings?->shippingCost,
            'sell_swap_contact_info' => $settings?->contactInfo,
        ];
    }

    /**
     * A list set to a custom currency keeps the real one in customCurrency (as GetMarketplaceListings reads it).
     */
    private function listCurrency(PlayerProfile $player): null|string
    {
        $settings = $player->sellSwapListSettings;

        if ($settings === null) {
            return null;
        }

        return $settings->currency === 'custom' ? $settings->customCurrency : $settings->currency;
    }

    private static function nullIfEmpty(string $value): null|string
    {
        return $value === '' ? null : $value;
    }

    private static function formatSeconds(null|int $seconds): null|string
    {
        if ($seconds === null) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
