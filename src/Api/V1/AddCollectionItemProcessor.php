<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<AddCollectionItemInput, CollectionItemResponse>
 */
final readonly class AddCollectionItemProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private GetPlayerProfile $getPlayerProfile,
        private GetCollectionItems $getCollectionItems,
        private CollectionRepository $collectionRepository,
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    /**
     * @param AddCollectionItemInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CollectionItemResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        /** @var string $collectionId */
        $collectionId = $uriVariables['collectionId'];
        $dbCollectionId = $collectionId === 'default' ? null : $collectionId;

        // Validate here so an invalid/unknown id surfaces as 404 instead of a wrapped 500 from the handler
        $this->puzzleRepository->get($data->puzzle_id);

        if ($dbCollectionId !== null) {
            $collection = $this->collectionRepository->get($dbCollectionId);

            if ($collection->player->id->toString() !== $playerId) {
                throw new CollectionNotFound();
            }

            $profile = $this->getPlayerProfile->byId($playerId);

            if ($profile->activeMembership === false) {
                throw new AccessDeniedHttpException('Active membership is required to add items to custom collections.');
            }
        }

        $this->messageBus->dispatch(
            new AddPuzzleToCollection(
                playerId: $playerId,
                puzzleId: $data->puzzle_id,
                collectionId: $dbCollectionId,
                comment: $data->comment,
            ),
        );

        $item = $this->getCollectionItems->getByPuzzleIdAndPlayerId($data->puzzle_id, $playerId, $dbCollectionId);

        if ($item === null) {
            throw new \RuntimeException('Collection item was not found after adding it to the collection.');
        }

        return new CollectionItemResponse(
            collection_item_id: $item->collectionItemId,
            puzzle_id: $item->puzzleId,
            puzzle_name: $item->puzzleName,
            manufacturer_name: $item->manufacturerName,
            pieces_count: $item->piecesCount,
            image: $item->image,
            comment: $item->comment,
            added_at: $item->addedAt->format('c'),
        );
    }
}
