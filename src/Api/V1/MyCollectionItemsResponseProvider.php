<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Results\CollectionItemOverview;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\PuzzleResponseFactory;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MyCollectionItemsResponse>
 */
final readonly class MyCollectionItemsResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private GetCollectionItems $getCollectionItems,
        private CollectionRepository $collectionRepository,
        private PuzzleResponseFactory $puzzleResponseFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MyCollectionItemsResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        /** @var string $collectionId */
        $collectionId = $uriVariables['collectionId'];

        $dbCollectionId = $collectionId === 'default' ? null : $collectionId;

        if ($dbCollectionId !== null) {
            // Validates the id format too - garbage ids (e.g. "undefined") must 404, not crash the query
            $collection = $this->collectionRepository->get($dbCollectionId);

            if ($collection->player->id->toString() !== $playerId) {
                throw new CollectionNotFound();
            }
        }

        $items = $this->getCollectionItems->byCollectionAndPlayer($dbCollectionId, $playerId);

        // One batch per list, whatever its size: the owner's own solves and
        // (member, not opted out, results:read) predictions - self-only data
        $insights = $this->puzzleResponseFactory->insightsFor(
            array_map(static fn (CollectionItemOverview $item): string => $item->puzzleId, $items),
            solvesOfPlayerId: $playerId,
            includePrediction: true,
        );

        return new MyCollectionItemsResponse(
            collectionId: $collectionId,
            count: count($items),
            items: array_map(
                static fn(CollectionItemOverview $item) => new CollectionItemResponse(
                    collectionItemId: $item->collectionItemId,
                    puzzleId: $item->puzzleId,
                    puzzleName: $item->puzzleName,
                    manufacturerName: $item->manufacturerName,
                    piecesCount: $item->piecesCount,
                    image: $item->image,
                    comment: $item->comment,
                    addedAt: $item->addedAt->format('c'),
                    statistics: $insights->statistics($item->puzzleId),
                    difficulty: $insights->difficulty($item->puzzleId),
                    prediction: $insights->prediction($item->puzzleId),
                    solves: $insights->solves($item->puzzleId),
                ),
                $items,
            ),
        );
    }
}
