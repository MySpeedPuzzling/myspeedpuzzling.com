<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetMarketplaceListings;
use SpeedPuzzling\Web\Results\MarketplaceListingItem;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MarketplaceListing
{
    use DefaultActionTrait;

    private const int PER_PAGE = 24;

    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    #[LiveProp(writable: true, url: true)]
    public string $manufacturer = '';

    #[LiveProp(writable: true, url: true)]
    public null|int $piecesMin = null;

    #[LiveProp(writable: true, url: true)]
    public null|int $piecesMax = null;

    #[LiveProp(writable: true, url: true)]
    public string $listingType = '';

    #[LiveProp(writable: true, url: true)]
    public null|float $priceMin = null;

    #[LiveProp(writable: true, url: true)]
    public null|float $priceMax = null;

    #[LiveProp(writable: true, url: true)]
    public string $condition = '';

    #[LiveProp(writable: true, url: true)]
    public string $shipsTo = '';

    #[LiveProp(writable: true, url: true)]
    public string $sort = 'newest';

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    /** @var null|array<MarketplaceListingItem> */
    private null|array $cachedItems = null;

    private null|int $cachedCount = null;

    public function __construct(
        readonly private GetMarketplaceListings $getMarketplaceListings,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[PreReRender]
    public function preReRender(): void
    {
        // Reset cache on re-render
        $this->cachedItems = null;
        $this->cachedCount = null;
    }

    /**
     * @return array<MarketplaceListingItem>
     */
    public function getItems(): array
    {
        if ($this->cachedItems !== null) {
            return $this->cachedItems;
        }

        $this->cachedItems = $this->getMarketplaceListings->search(
            searchTerm: $this->search !== '' ? $this->search : null,
            manufacturerId: $this->manufacturer !== '' ? $this->manufacturer : null,
            piecesMin: $this->piecesMin,
            piecesMax: $this->piecesMax,
            listingType: $this->getListingTypeEnum(),
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            condition: $this->getConditionEnum(),
            shipsToCountry: $this->shipsTo !== '' ? $this->shipsTo : null,
            sort: $this->sort,
            limit: self::PER_PAGE,
            offset: ($this->page - 1) * self::PER_PAGE,
        );

        return $this->cachedItems;
    }

    public function getResultCount(): int
    {
        if ($this->cachedCount !== null) {
            return $this->cachedCount;
        }

        $this->cachedCount = $this->getMarketplaceListings->count(
            searchTerm: $this->search !== '' ? $this->search : null,
            manufacturerId: $this->manufacturer !== '' ? $this->manufacturer : null,
            piecesMin: $this->piecesMin,
            piecesMax: $this->piecesMax,
            listingType: $this->getListingTypeEnum(),
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            condition: $this->getConditionEnum(),
            shipsToCountry: $this->shipsTo !== '' ? $this->shipsTo : null,
        );

        return $this->cachedCount;
    }

    /**
     * @return array<array{manufacturer_id: string, manufacturer_name: string, listing_count: int}>
     */
    public function getManufacturers(): array
    {
        return $this->getMarketplaceListings->getManufacturersWithActiveListings();
    }

    public function getTotalPages(): int
    {
        return max(1, (int) ceil($this->getResultCount() / self::PER_PAGE));
    }

    public function getDefaultShipsTo(): string
    {
        if ($this->shipsTo !== '') {
            return $this->shipsTo;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile !== null && $profile->country !== null) {
            return $profile->country;
        }

        return '';
    }

    private function getListingTypeEnum(): null|ListingType
    {
        if ($this->listingType === '') {
            return null;
        }

        return ListingType::tryFrom($this->listingType);
    }

    private function getConditionEnum(): null|PuzzleCondition
    {
        if ($this->condition === '') {
            return null;
        }

        return PuzzleCondition::tryFrom($this->condition);
    }
}
