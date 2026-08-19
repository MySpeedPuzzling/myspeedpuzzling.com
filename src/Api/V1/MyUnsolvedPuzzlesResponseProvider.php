<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\PuzzleLibraryItemsFactory;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /api/v1/me/unsolved-puzzles - the token owner's own unsolved puzzles
 * (collections + borrowed), always complete.
 *
 * @implements ProviderInterface<UnsolvedPuzzlesResponse>
 */
final readonly class MyUnsolvedPuzzlesResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PuzzleLibraryItemsFactory $itemsFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnsolvedPuzzlesResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();
        $items = $this->itemsFactory->unsolvedPuzzles($playerId);

        return new UnsolvedPuzzlesResponse(
            playerId: $playerId,
            count: count($items),
            items: $items,
        );
    }
}
