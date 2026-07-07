<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Message\RemoveCollectionItem;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<DeleteCollectionItemInput, void>
 */
final readonly class DeleteCollectionItemProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private CollectionItemRepository $collectionItemRepository,
    ) {
    }

    /**
     * @param DeleteCollectionItemInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        /** @var string $collectionId */
        $collectionId = $uriVariables['collectionId'];

        /** @var string $itemId */
        $itemId = $uriVariables['itemId'];

        // Validate here so an invalid/unknown id surfaces as 404 instead of a wrapped 500 from the handler
        $item = $this->collectionItemRepository->get($itemId);

        if ($item->player->id->toString() !== $playerId) {
            throw new AccessDeniedHttpException('You can only delete your own collection items.');
        }

        $dbCollectionId = $collectionId === 'default' ? null : $collectionId;

        if ($item->collection?->id->toString() !== $dbCollectionId) {
            throw new CollectionItemNotFound();
        }

        $this->messageBus->dispatch(
            new RemoveCollectionItem(
                collectionItemId: $itemId,
                playerId: $playerId,
            ),
        );
    }
}
