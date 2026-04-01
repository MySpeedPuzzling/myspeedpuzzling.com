<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
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

        if ($dbCollectionId !== null) {
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

        return new CollectionItemResponse(
            collection_item_id: '',
            puzzle_id: $data->puzzle_id,
            puzzle_name: '',
            manufacturer_name: null,
            pieces_count: 0,
            image: null,
            comment: $data->comment,
            added_at: (new \DateTimeImmutable())->format('c'),
        );
    }
}
