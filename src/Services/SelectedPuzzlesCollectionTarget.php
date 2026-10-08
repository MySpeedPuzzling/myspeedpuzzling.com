<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\FormData\CollectionPuzzleActionFormData;
use SpeedPuzzling\Web\Message\CreateCollection;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The collection picked in the target picker of selected puzzles (move/copy on a collection page, "Add to collection…"
 * on the other lists - docs/features/collections/bulk-actions.md), or a new one from a typed name (an existing name =
 * that collection).
 */
readonly final class SelectedPuzzlesCollectionTarget
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, null|string> $choices name => collection id, the collections the picker offered
     * @return array{null|string, string} the collection id (null = the system collection) and its name
     * @throws CollectionNotFound
     */
    public function resolve(CollectionPuzzleActionFormData $formData, string $playerId, array $choices): array
    {
        $target = $formData->collection;

        if ($target === null || $target === Collection::SYSTEM_ID) {
            return [null, $this->translator->trans('collections.system_name')];
        }

        if (Uuid::isValid($target)) {
            $name = array_search($target, $choices, true);

            if ($name === false) {
                throw new CollectionNotFound();
            }

            return [$target, (string) $name];
        }

        $newCollectionId = Uuid::uuid7()->toString();

        try {
            $this->messageBus->dispatch(new CreateCollection(
                collectionId: $newCollectionId,
                playerId: $playerId,
                name: $target,
                description: $formData->collectionDescription,
                visibility: $formData->collectionVisibility,
            ));
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof CollectionAlreadyExists) {
                return [$previous->collectionId, $target];
            }

            throw $exception;
        }

        return [$newCollectionId, $target];
    }
}
