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

        return new MyCollectionItemsResponse(
            collection_id: $collectionId,
            count: count($items),
            items: array_map(
                static fn(CollectionItemOverview $item) => new CollectionItemResponse(
                    collection_item_id: $item->collectionItemId,
                    puzzle_id: $item->puzzleId,
                    puzzle_name: $item->puzzleName,
                    manufacturer_name: $item->manufacturerName,
                    pieces_count: $item->piecesCount,
                    image: $item->image,
                    comment: $item->comment,
                    added_at: $item->addedAt->format('c'),
                ),
                $items,
            ),
        );
    }
}
