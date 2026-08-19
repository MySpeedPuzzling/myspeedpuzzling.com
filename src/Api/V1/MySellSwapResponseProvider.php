<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Services\Api\PuzzleLibraryItemsFactory;

/**
 * GET /api/v1/me/sell-swap - the token owner's own sell/swap list.
 *
 * @implements ProviderInterface<SellSwapResponse>
 */
final readonly class MySellSwapResponseProvider implements ProviderInterface
{
    public function __construct(
        private ApiTokenOwner $tokenOwner,
        private PuzzleLibraryItemsFactory $itemsFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SellSwapResponse
    {
        // access_control admits only tokens with a player behind them (PAT, auth-code),
        // so the owner is always there; the profile (it carries the list-wide
        // currency) is loaded once and memoised - the insights batch reuses it.
        $profile = $this->tokenOwner->profile() ?? throw new PlayerNotFound();

        $items = $this->itemsFactory->sellSwap($profile);

        return new SellSwapResponse(
            playerId: $profile->playerId,
            count: count($items),
            items: $items,
        );
    }
}
