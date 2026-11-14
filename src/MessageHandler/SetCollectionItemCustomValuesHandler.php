<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SetCollectionItemCustomValues;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\CustomFieldRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CustomFields\SubmittedValuesChange;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * THE writer of the owner's own values on a collection item (docs/features/custom-fields.md "Collection items"):
 * the acting player's own item, their own fields for collection items, nothing else - no event, no notification.
 */
#[AsMessageHandler]
readonly final class SetCollectionItemCustomValuesHandler
{
    public function __construct(
        private CollectionItemRepository $collectionItemRepository,
        private PlayerRepository $playerRepository,
        private CustomFieldRepository $customFieldRepository,
    ) {
    }

    /**
     * @throws CollectionItemNotFound
     * @throws PlayerNotFound
     * @throws InvalidCustomValues
     */
    public function __invoke(SetCollectionItemCustomValues $message): void
    {
        $collectionItem = $this->collectionItemRepository->get($message->collectionItemId);
        $player = $this->playerRepository->get($message->playerId);

        // Somebody else's item is "not found", like for the comment
        if ($collectionItem->player->id->equals($player->id) === false) {
            throw new CollectionItemNotFound();
        }

        $fields = [];

        // Somebody else's field, an archived one, one that is not for collection items: all the same "no such field"
        foreach ($this->customFieldRepository->ofPlayer($player) as $field) {
            if ($field->isUsableOnCollectionItems()) {
                $fields[$field->id->toString()] = $field;
            }
        }

        $stored = $collectionItem->customValues();

        // Everything checked before anything changes - a refused handler's changes stay in the entity manager
        $values = SubmittedValuesChange::apply($stored, $fields, $message->values);

        if ($values->values !== $stored->values) {
            $collectionItem->changeCustomValues($values);
        }
    }
}
