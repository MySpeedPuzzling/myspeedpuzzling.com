<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\PuzzleLibraryItemsFactory;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /api/v1/me/lend-borrow - the token owner's own lend/borrow list, always
 * complete (the website shows a non-member their own list too - lending is
 * the members-only action, not reading).
 *
 * @implements ProviderInterface<LendBorrowResponse>
 */
final readonly class MyLendBorrowResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PuzzleLibraryItemsFactory $itemsFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LendBorrowResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();
        $items = $this->itemsFactory->lendBorrow($playerId);

        return new LendBorrowResponse(
            playerId: $playerId,
            count: count($items),
            items: $items,
        );
    }
}
