<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\PuzzleLibraryItemsFactory;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /api/v1/me/wishlist - the token owner's own wishlist, always complete.
 *
 * @implements ProviderInterface<WishlistResponse>
 */
final readonly class MyWishlistResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PuzzleLibraryItemsFactory $itemsFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WishlistResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();
        $items = $this->itemsFactory->wishlist($playerId);

        return new WishlistResponse(
            player_id: $playerId,
            count: count($items),
            items: $items,
        );
    }
}
