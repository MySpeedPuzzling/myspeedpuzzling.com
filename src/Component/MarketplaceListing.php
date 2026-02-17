<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetMarketplaceListings;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Results\MarketplaceListingItem;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MarketplaceListing
{
    use DefaultActionTrait;

    private const int PER_PAGE = 21;

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
    public bool $shipToMyCountry = false;

    #[LiveProp(writable: true, url: true)]
    public string $sort = 'newest';

    #[LiveProp(writable: true, url: true)]
    public bool $myOffers = false;

    #[LiveProp(writable: true, url: true)]
    public string $puzzleId = '';

    #[LiveProp(writable: true)]
    public int $page = 1;

    #[LiveProp]
    public string $filterHash = '';

    /** @var null|array<MarketplaceListingItem> */
    private null|array $cachedItems = null;

    private null|int $cachedCount = null;

    public function __construct(
        readonly private GetMarketplaceListings $getMarketplaceListings,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[PreReRender]
    public function preReRender(): void
    {
        $this->cachedItems = null;
        $this->cachedCount = null;

        $currentHash = $this->computeFilterHash();

        if ($this->filterHash !== '' && $this->filterHash !== $currentHash) {
            $this->page = 1;
        }

        $this->filterHash = $currentHash;
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
            shipsToCountry: $this->getShipsToCountry(),
            sellerId: $this->getMyOffersSellerId(),
            puzzleId: $this->puzzleId !== '' ? $this->puzzleId : null,
            sort: $this->sort,
            limit: $this->page * self::PER_PAGE,
            offset: 0,
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
            shipsToCountry: $this->getShipsToCountry(),
            sellerId: $this->getMyOffersSellerId(),
            puzzleId: $this->puzzleId !== '' ? $this->puzzleId : null,
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

    public function hasMoreItems(): bool
    {
        return ($this->page * self::PER_PAGE) < $this->getResultCount();
    }

    #[LiveAction]
    public function loadMore(): void
    {
        $this->page++;
    }

    public function getUserCountry(): null|string
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile !== null && $profile->country !== null) {
            return $profile->country;
        }

        return null;
    }

    public function getPuzzleName(): null|string
    {
        if ($this->puzzleId === '') {
            return null;
        }

        try {
            $puzzle = $this->getPuzzleOverview->byId($this->puzzleId);
        } catch (PuzzleNotFound) {
            return null;
        }

        return $puzzle->puzzleName;
    }

    private function getShipsToCountry(): null|string
    {
        if ($this->shipToMyCountry === false) {
            return null;
        }

        return $this->getUserCountry();
    }

    private function getMyOffersSellerId(): null|string
    {
        if ($this->myOffers === false) {
            return null;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return null;
        }

        return $profile->playerId;
    }

    public function getReturnUrl(): string
    {
        $params = [];

        if ($this->search !== '') {
            $params['search'] = $this->search;
        }

        if ($this->manufacturer !== '') {
            $params['manufacturer'] = $this->manufacturer;
        }

        if ($this->piecesMin !== null) {
            $params['piecesMin'] = $this->piecesMin;
        }

        if ($this->piecesMax !== null) {
            $params['piecesMax'] = $this->piecesMax;
        }

        if ($this->listingType !== '') {
            $params['listingType'] = $this->listingType;
        }

        if ($this->priceMin !== null) {
            $params['priceMin'] = $this->priceMin;
        }

        if ($this->priceMax !== null) {
            $params['priceMax'] = $this->priceMax;
        }

        if ($this->condition !== '') {
            $params['condition'] = $this->condition;
        }

        if ($this->shipToMyCountry) {
            $params['shipToMyCountry'] = '1';
        }

        if ($this->sort !== 'newest') {
            $params['sort'] = $this->sort;
        }

        if ($this->myOffers) {
            $params['myOffers'] = '1';
        }

        if ($this->puzzleId !== '') {
            $params['puzzleId'] = $this->puzzleId;
        }

        return $this->urlGenerator->generate('marketplace', $params);
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

    private function computeFilterHash(): string
    {
        return md5(serialize([
            $this->search, $this->manufacturer, $this->piecesMin, $this->piecesMax,
            $this->listingType, $this->priceMin, $this->priceMax, $this->condition,
            $this->shipToMyCountry, $this->sort, $this->myOffers, $this->puzzleId,
        ]));
    }
}
