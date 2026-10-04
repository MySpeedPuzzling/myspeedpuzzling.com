<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEligibleForMarketplace;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetEventsWithSellersGoing;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Query\GetMarketplaceListings;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Results\EventWithSellersGoing;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Results\MarketplaceListingsCount;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\MarketplaceListingItem;
use SpeedPuzzling\Web\Services\ResolveDifficultyTiers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\DifficultyFilter;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\HintType;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

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

    /**
     * The piece-count chip (a PiecesRange param) - only a way to set the two
     * bounds above, which stay the source of truth (and the URL).
     */
    #[LiveProp(writable: true, onUpdated: 'onPiecesUpdated')]
    public null|string $pieces = null;

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
    public string $sellerCountry = '';

    #[LiveProp(writable: true, url: true)]
    public string $sort = 'newest';

    #[LiveProp(writable: true, url: true)]
    public bool $myOffers = false;

    /**
     * Difficulty chips (members): tier values as strings, "0" = not rated yet (Value\DifficultyFilter)
     *
     * @var list<string>
     */
    #[LiveProp(writable: true, url: true)]
    public array $difficulty = [];

    /**
     * "Pick up at an event" (docs/features/marketplace/11-events.md): a marketplace event's competition id - the
     * going sellers' listings only, bringing first, shipping filters off. Anything that is not a marketplace event
     * counts as no event (getChosenEvent()).
     */
    #[LiveProp(writable: true, url: true)]
    public string $event = '';

    /** With an event: only the listings the sellers are bringing */
    #[LiveProp(writable: true, url: true)]
    public bool $onlyBringing = false;

    #[LiveProp(writable: true)]
    public string $puzzleId = '';

    #[LiveProp(writable: true)]
    public int $page = 1;

    #[LiveProp]
    public string $filterHash = '';

    /**
     * The overview the page controller already loaded for puzzleId - saves reloading it for the
     * first render. Not a LiveProp: re-renders load it (once) themselves.
     */
    public null|PuzzleOverview $puzzleOverview = null;

    /** @var null|array<MarketplaceListingItem> */
    private null|array $cachedItems = null;

    private null|MarketplaceListingsCount $cachedCounts = null;

    /** @var null|list<EventWithSellersGoing> */
    private null|array $cachedEventChoices = null;

    private bool $chosenEventResolved = false;

    private null|MarketplaceEvent $chosenEvent = null;

    private bool $filteredPuzzleOverviewLoaded = false;

    private null|PuzzleOverview $filteredPuzzleOverview = null;

    /** @var null|array<string, DifficultyTier> */
    private null|array $cachedDifficultyTiers = null;

    private bool $difficultyTiersResolved = false;

    public function __construct(
        readonly private GetMarketplaceListings $getMarketplaceListings,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsHintDismissed $isHintDismissed,
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private TranslatorInterface $translator,
        readonly private ResolveDifficultyTiers $resolveDifficultyTiers,
        readonly private GetEventsWithSellersGoing $getEventsWithSellersGoing,
        readonly private GetMarketplaceEvents $getMarketplaceEvents,
    ) {
    }

    public function onPiecesUpdated(): void
    {
        $range = PiecesRange::parse($this->pieces);
        $this->piecesMin = $range?->minPieces;
        $this->piecesMax = $range?->maxPieces;
    }

    #[PostMount]
    public function normalizePieces(): void
    {
        $range = PiecesRange::fromBounds($this->piecesMin, $this->piecesMax);
        $this->piecesMin = $range?->minPieces;
        $this->piecesMax = $range?->maxPieces;
        $this->pieces = $range?->toParam();
        // Comes from the URL as well
        $this->difficulty = DifficultyFilter::normalize($this->difficulty);
        $this->event = strtolower(trim($this->event));

        // Sorting by difficulty is members-only, like the difficulty filter
        if (in_array($this->sort, GetMarketplaceListings::DIFFICULTY_SORTS, true) && $this->isMember() === false) {
            $this->sort = 'newest';
        }
    }

    #[PreReRender]
    public function preReRender(): void
    {
        $this->normalizePieces();
        $this->cachedItems = null;
        $this->cachedCounts = null;
        $this->chosenEventResolved = false;
        $this->chosenEvent = null;
        $this->filteredPuzzleOverviewLoaded = false;
        $this->filteredPuzzleOverview = null;
        $this->cachedDifficultyTiers = null;
        $this->difficultyTiersResolved = false;

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
            sellerCountry: $this->getSellerCountry(),
            sellerId: $this->getMyOffersSellerId(),
            puzzleId: $this->puzzleId !== '' && Uuid::isValid($this->puzzleId) ? $this->puzzleId : null,
            sort: $this->sort,
            limit: $this->page * self::PER_PAGE,
            offset: 0,
            difficultyTiers: $this->getDifficultyFilter(),
            event: $this->getChosenEvent()?->competitionId,
            onlyBringing: $this->onlyBringing,
            viewerId: $this->retrieveLoggedUserProfile->getProfile()?->playerId,
        );

        return $this->cachedItems;
    }

    public function getResultCount(): int
    {
        return $this->getCounts()->total;
    }

    /**
     * The count - and with an event both parts of the list (bringing / to ask), from the same statement.
     */
    public function getCounts(): MarketplaceListingsCount
    {
        if ($this->cachedCounts !== null) {
            return $this->cachedCounts;
        }

        $this->cachedCounts = $this->getMarketplaceListings->countParts(
            searchTerm: $this->search !== '' ? $this->search : null,
            manufacturerId: $this->manufacturer !== '' ? $this->manufacturer : null,
            piecesMin: $this->piecesMin,
            piecesMax: $this->piecesMax,
            listingType: $this->getListingTypeEnum(),
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            condition: $this->getConditionEnum(),
            shipsToCountry: $this->getShipsToCountry(),
            sellerCountry: $this->getSellerCountry(),
            sellerId: $this->getMyOffersSellerId(),
            puzzleId: $this->puzzleId !== '' && Uuid::isValid($this->puzzleId) ? $this->puzzleId : null,
            difficultyTiers: $this->getDifficultyFilter(),
            event: $this->getChosenEvent()?->competitionId,
            onlyBringing: $this->onlyBringing,
        );

        return $this->cachedCounts;
    }

    /**
     * The select's options: upcoming marketplace events sellers with published listings are going to (cached for
     * every visitor, GetEventsWithSellersGoing).
     *
     * @return list<EventWithSellersGoing>
     */
    public function getEventChoices(): array
    {
        if ($this->cachedEventChoices === null) {
            $this->cachedEventChoices = $this->getEventsWithSellersGoing->all();
        }

        return $this->cachedEventChoices;
    }

    /**
     * The chosen marketplace event, or null - for no event and for anything that is not a marketplace event
     * (an online or past event, a typo): the marketplace then ignores it. Found among the select's options; only an
     * event missing there (the cached options are up to 10 minutes old) costs a lookup.
     */
    public function getChosenEvent(): null|MarketplaceEvent
    {
        if ($this->chosenEventResolved === false) {
            $this->chosenEvent = $this->resolveChosenEvent();
            $this->chosenEventResolved = true;
        }

        return $this->chosenEvent;
    }

    private function resolveChosenEvent(): null|MarketplaceEvent
    {
        if ($this->event === '' || Uuid::isValid($this->event) === false) {
            return null;
        }

        foreach ($this->getEventChoices() as $choice) {
            if ($choice->event->competitionId === $this->event) {
                return $choice->event;
            }
        }

        try {
            return $this->getMarketplaceEvents->byId($this->event);
        } catch (CompetitionNotEligibleForMarketplace) {
            return null;
        }
    }

    #[LiveAction]
    public function clearEvent(): void
    {
        $this->event = '';
        $this->onlyBringing = false;
    }

    /**
     * @return array<array{manufacturer_id: string, manufacturer_name: string, listing_count: int}>
     */
    public function getManufacturers(): array
    {
        return $this->getMarketplaceListings->getManufacturersWithActiveListings();
    }

    /**
     * Difficulty tier of the listed puzzles for the cards' image corner - members only, null for everyone else.
     *
     * @return null|array<string, DifficultyTier>
     */
    public function getDifficultyTiers(): null|array
    {
        if ($this->difficultyTiersResolved === false) {
            $this->cachedDifficultyTiers = $this->resolveDifficultyTiers->forViewer(
                $this->retrieveLoggedUserProfile->getProfile(),
                array_map(static fn (MarketplaceListingItem $item): string => $item->puzzleId, $this->getItems()),
            );
            $this->difficultyTiersResolved = true;
        }

        return $this->cachedDifficultyTiers;
    }

    /**
     * @return list<array{value: string, tier: null|DifficultyTier}>
     */
    public function getDifficultyOptions(): array
    {
        return DifficultyFilter::options();
    }

    private function isMember(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile()?->activeMembership === true;
    }

    /**
     * The difficulty filter is members-only: for anybody else a difficulty in the URL is ignored.
     *
     * @return list<int>
     */
    private function getDifficultyFilter(): array
    {
        if ($this->difficulty === [] || $this->isMember() === false) {
            return [];
        }

        return DifficultyFilter::toInts(DifficultyFilter::normalize($this->difficulty));
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

    public function isSettingsChecklistDismissed(): bool
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return false;
        }

        return ($this->isHintDismissed)($profile->playerId, HintType::MarketplaceSettingsChecklist);
    }

    public function getPuzzleName(): null|string
    {
        return $this->getFilteredPuzzleOverview()?->puzzleName;
    }

    public function getPuzzleImage(): null|string
    {
        return $this->getFilteredPuzzleOverview()?->puzzleImage;
    }

    public function getPuzzleImageRatio(): null|float
    {
        return $this->getFilteredPuzzleOverview()?->puzzleImageRatio;
    }

    /**
     * The template reads name, image and image ratio - one lookup for all three.
     */
    private function getFilteredPuzzleOverview(): null|PuzzleOverview
    {
        if ($this->filteredPuzzleOverviewLoaded === false) {
            $this->filteredPuzzleOverview = $this->loadFilteredPuzzleOverview();
            $this->filteredPuzzleOverviewLoaded = true;
        }

        return $this->filteredPuzzleOverview;
    }

    private function loadFilteredPuzzleOverview(): null|PuzzleOverview
    {
        if ($this->puzzleId === '' || !Uuid::isValid($this->puzzleId)) {
            return null;
        }

        if ($this->puzzleOverview !== null && $this->puzzleOverview->puzzleId === $this->puzzleId) {
            return $this->puzzleOverview;
        }

        try {
            return $this->getPuzzleOverview->byId($this->puzzleId);
        } catch (PuzzleNotFound) {
            return null;
        }
    }

    /**
     * Shipping filters are off while an event is chosen - a hand-over in person.
     */
    private function getShipsToCountry(): null|string
    {
        if ($this->shipToMyCountry === false || $this->getChosenEvent() !== null) {
            return null;
        }

        return $this->getUserCountry();
    }

    private function getSellerCountry(): null|string
    {
        if ($this->sellerCountry === '' || $this->getChosenEvent() !== null) {
            return null;
        }

        return $this->sellerCountry;
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

        if ($this->sellerCountry !== '') {
            $params['sellerCountry'] = $this->sellerCountry;
        }

        if ($this->sort !== 'newest') {
            $params['sort'] = $this->sort;
        }

        if ($this->myOffers) {
            $params['myOffers'] = '1';
        }

        if ($this->difficulty !== []) {
            $params['difficulty'] = $this->difficulty;
        }

        if ($this->event !== '') {
            $params['event'] = $this->event;

            if ($this->onlyBringing) {
                $params['onlyBringing'] = '1';
            }
        }

        if ($this->puzzleId !== '') {
            return $this->urlGenerator->generate('marketplace_puzzle', array_merge(['puzzleId' => $this->puzzleId], $params));
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
            $this->shipToMyCountry, $this->sellerCountry, $this->sort, $this->myOffers, $this->puzzleId,
            $this->difficulty, $this->event, $this->onlyBringing,
        ]));
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getCountryChoicesGroupedByRegion(): array
    {
        $centralEurope = [
            CountryCode::cz, CountryCode::sk, CountryCode::pl, CountryCode::hu,
            CountryCode::at, CountryCode::si, CountryCode::ch, CountryCode::li,
        ];

        $westernEurope = [
            CountryCode::de, CountryCode::fr, CountryCode::nl, CountryCode::be,
            CountryCode::lu, CountryCode::ie, CountryCode::gb, CountryCode::mc,
        ];

        $southernEurope = [
            CountryCode::es, CountryCode::pt, CountryCode::it, CountryCode::gr,
            CountryCode::hr, CountryCode::ba, CountryCode::rs, CountryCode::me,
            CountryCode::mk, CountryCode::al, CountryCode::mt, CountryCode::cy,
        ];

        $northernEurope = [
            CountryCode::se, CountryCode::no, CountryCode::dk, CountryCode::fi,
            CountryCode::is, CountryCode::ee, CountryCode::lv, CountryCode::lt,
        ];

        $easternEurope = [
            CountryCode::ro, CountryCode::bg, CountryCode::ua, CountryCode::md,
            CountryCode::by,
        ];

        $northAmerica = [
            CountryCode::us, CountryCode::ca, CountryCode::mx,
        ];

        $groups = [
            $this->translator->trans('sell_swap_list.settings.region.central_europe') => $centralEurope,
            $this->translator->trans('sell_swap_list.settings.region.western_europe') => $westernEurope,
            $this->translator->trans('sell_swap_list.settings.region.southern_europe') => $southernEurope,
            $this->translator->trans('sell_swap_list.settings.region.northern_europe') => $northernEurope,
            $this->translator->trans('sell_swap_list.settings.region.eastern_europe') => $easternEurope,
            $this->translator->trans('sell_swap_list.settings.region.north_america') => $northAmerica,
        ];

        $usedCodes = [];
        foreach ($groups as $countries) {
            foreach ($countries as $country) {
                $usedCodes[] = $country->name;
            }
        }

        $restOfWorld = [];
        foreach (CountryCode::cases() as $country) {
            if (!in_array($country->name, $usedCodes, true)) {
                $restOfWorld[] = $country;
            }
        }

        $groups[$this->translator->trans('sell_swap_list.settings.region.rest_of_world')] = $restOfWorld;

        $choices = [];
        foreach ($groups as $groupName => $countries) {
            foreach ($countries as $country) {
                $choices[$groupName][$country->name] = $country->value;
            }
        }

        return $choices;
    }
}
