<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use SpeedPuzzling\Web\Message\DeleteCollection;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<DeleteCollectionInput, void>
 */
final readonly class DeleteCollectionProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    /**
     * @param DeleteCollectionInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        /** @var string $collectionId */
        $collectionId = $uriVariables['collectionId'];

        if ($collectionId === 'default') {
            throw new BadRequestHttpException('The default collection cannot be deleted.');
        }

        $profile = $this->getPlayerProfile->byId($playerId);

        if ($profile->activeMembership === false) {
            throw new AccessDeniedHttpException('Active membership is required to delete custom collections.');
        }

        $this->messageBus->dispatch(
            new DeleteCollection(
                collectionId: $collectionId,
                playerId: $playerId,
            ),
        );
    }
}
