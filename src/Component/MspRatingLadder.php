<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Results\PlayerRatingEntry;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MspRatingLadder
{
    use DefaultActionTrait;

    private const int PER_PAGE = 100;
    private const int PIECES_COUNT = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0];

    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    #[LiveProp(writable: true, url: true)]
    public string $country = '';

    #[LiveProp(writable: true, url: true)]
    public bool $onlyFavorites = false;

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    #[LiveProp]
    public string $filterHash = '';

    /** @var null|list<PlayerRatingEntry> */
    private null|array $cachedEntries = null;

    private null|int $cachedTotalCount = null;

    public function __construct(
        readonly private GetPlayerRatingRanking $getPlayerRatingRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[PreReRender]
    public function preReRender(): void
    {
        $this->cachedEntries = null;
        $this->cachedTotalCount = null;

        $currentHash = $this->computeFilterHash();

        if ($this->filterHash !== '' && $this->filterHash !== $currentHash) {
            $this->page = 1;
        }

        $this->filterHash = $currentHash;
    }

    /**
     * @return list<PlayerRatingEntry>
     */
    public function getEntries(): array
    {
        if ($this->cachedEntries !== null) {
            return $this->cachedEntries;
        }

        $offset = (max(1, $this->page) - 1) * self::PER_PAGE;

        $this->cachedEntries = $this->getPlayerRatingRanking->ranking(
            self::PIECES_COUNT,
            self::PER_PAGE,
            $offset,
            $this->country !== '' ? $this->country : null,
            $this->search !== '' ? $this->search : null,
            $this->getFavoriteOfPlayerId(),
        );

        return $this->cachedEntries;
    }

    public function getTotalCount(): int
    {
        if ($this->cachedTotalCount !== null) {
            return $this->cachedTotalCount;
        }

        $this->cachedTotalCount = $this->getPlayerRatingRanking->totalCount(
            self::PIECES_COUNT,
            $this->country !== '' ? $this->country : null,
            $this->search !== '' ? $this->search : null,
            $this->getFavoriteOfPlayerId(),
        );

        return $this->cachedTotalCount;
    }

    public function getTotalPages(): int
    {
        return max(1, (int) ceil($this->getTotalCount() / self::PER_PAGE));
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->country !== '' || $this->onlyFavorites;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function getCountries(): array
    {
        $codes = $this->getPlayerRatingRanking->distinctCountries(self::PIECES_COUNT);
        $result = [];

        foreach ($codes as $code) {
            $countryEnum = CountryCode::fromCode($code);

            if ($countryEnum !== null) {
                $result[] = ['code' => $code, 'name' => $countryEnum->value];
            }
        }

        return $result;
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, min($page, $this->getTotalPages()));
    }

    private function getFavoriteOfPlayerId(): null|string
    {
        if ($this->onlyFavorites === false) {
            return null;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        return $profile?->playerId;
    }

    private function computeFilterHash(): string
    {
        return md5(serialize([$this->search, $this->country, $this->onlyFavorites]));
    }
}
