<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\Api\PuzzleLibraryItemsFactory;
use SpeedPuzzling\Web\Services\Api\PuzzleLibraryVisibility;
use SpeedPuzzling\Web\Value\CollectionVisibility;

/**
 * GET /api/v1/players/{playerId}/sell-swap - another player's sell/swap list.
 * The list is public for everyone on the website (SellSwapListDetailController
 * has no visibility check), so only a private profile zeroes it (count 0, no
 * items, no batch query) - and the player behind the token always sees their
 * own.
 *
 * @implements ProviderInterface<SellSwapResponse>
 */
final readonly class PlayerSellSwapResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
        private PuzzleLibraryVisibility $visibility,
        private PuzzleLibraryItemsFactory $itemsFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SellSwapResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        // validates the id, 404 for an unknown player
        $profile = $this->getPlayerProfile->byId($playerId);

        $items = $this->visibility->isVisibleToTokenOwner($profile, CollectionVisibility::Public)
            ? $this->itemsFactory->sellSwap($profile)
            : [];

        return new SellSwapResponse(
            playerId: $playerId,
            count: count($items),
            items: $items,
        );
    }
}
