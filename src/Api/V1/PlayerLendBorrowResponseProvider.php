<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\Api\PuzzleLibraryItemsFactory;
use SpeedPuzzling\Web\Services\Api\PuzzleLibraryVisibility;

/**
 * GET /api/v1/players/{playerId}/lend-borrow - another player's lend/borrow
 * list under the website's rule (LendBorrowListDetailController): visible
 * when the player made the list public or the token belongs to that player;
 * a private list and a private profile are zeroed (count 0, no items, no
 * batch query), never 403, like every /players/{id} endpoint.
 *
 * @implements ProviderInterface<LendBorrowResponse>
 */
final readonly class PlayerLendBorrowResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
        private PuzzleLibraryVisibility $visibility,
        private PuzzleLibraryItemsFactory $itemsFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LendBorrowResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        // validates the id, 404 for an unknown player
        $profile = $this->getPlayerProfile->byId($playerId);

        $items = $this->visibility->isVisibleToTokenOwner($profile, $profile->lendBorrowListVisibility)
            ? $this->itemsFactory->lendBorrow($playerId)
            : [];

        return new LendBorrowResponse(
            player_id: $playerId,
            count: count($items),
            items: $items,
        );
    }
}
