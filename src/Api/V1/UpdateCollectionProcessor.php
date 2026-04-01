<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use SpeedPuzzling\Web\Message\EditCollection;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<UpdateCollectionInput, CollectionResponse>
 */
final readonly class UpdateCollectionProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    /**
     * @param UpdateCollectionInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CollectionResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        /** @var string $collectionId */
        $collectionId = $uriVariables['collectionId'];

        if ($collectionId === 'default') {
            throw new BadRequestHttpException('The default collection cannot be edited.');
        }

        $profile = $this->getPlayerProfile->byId($playerId);

        if ($profile->activeMembership === false) {
            throw new AccessDeniedHttpException('Active membership is required to edit custom collections.');
        }

        $visibility = CollectionVisibility::tryFrom($data->visibility) ?? CollectionVisibility::Private;

        $this->messageBus->dispatch(
            new EditCollection(
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
