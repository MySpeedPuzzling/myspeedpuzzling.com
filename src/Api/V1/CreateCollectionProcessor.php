<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<CreateCollectionInput, CollectionResponse>
 */
final readonly class CreateCollectionProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    /**
     * @param CreateCollectionInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CollectionResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();
        $profile = $this->getPlayerProfile->byId($playerId);

        if ($profile->activeMembership === false) {
            throw new AccessDeniedHttpException('Active membership is required to create custom collections.');
        }

        $collectionId = Uuid::uuid7()->toString();
        $visibility = CollectionVisibility::tryFrom($data->visibility) ?? CollectionVisibility::Private;

        $this->messageBus->dispatch(
            new CreateCollection(
                collectionId: $collectionId,
                playerId: $playerId,
                name: $data->name,
                description: $data->description,
                visibility: $visibility,
            ),
        );

        return new CollectionResponse(
            collection_id: $collectionId,
            name: $data->name,
            description: $data->description,
            visibility: $visibility->value,
        );
    }
}
