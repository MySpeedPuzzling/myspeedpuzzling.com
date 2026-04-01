<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<DeleteCollectionItemInput, void>
 */
final readonly class DeleteCollectionItemProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
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

        /** @var string $itemId */
        $itemId = $uriVariables['itemId'];

        $item = $this->entityManager->find(CollectionItem::class, $itemId);

        if ($item === null) {
            throw new NotFoundHttpException('Collection item not found.');
        }

        if ($item->player->id->toString() !== $playerId) {
            throw new AccessDeniedHttpException('You can only delete your own collection items.');
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }
}
