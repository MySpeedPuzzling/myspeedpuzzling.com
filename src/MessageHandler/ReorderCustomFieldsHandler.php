<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ReorderCustomFields;
use SpeedPuzzling\Web\Repository\CustomFieldRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReorderCustomFieldsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private CustomFieldRepository $customFieldRepository,
        private IdLock $idLock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(ReorderCustomFields $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $this->idLock->lockUntilCommit($player->id);

        $fields = [];

        foreach ($this->customFieldRepository->ofPlayer($player) as $field) {
            $fields[$field->id->toString()] = $field;
        }

        // The ids given first, in their order (an id that is not the player's field means nothing), then the rest as it was
        $ordered = [];

        foreach ($message->customFieldIds as $customFieldId) {
            $customFieldId = strtolower($customFieldId);

            if (isset($fields[$customFieldId])) {
                $ordered[$customFieldId] = $fields[$customFieldId];
            }
        }

        $ordered += $fields;

        foreach (array_values($ordered) as $position => $field) {
            /** @var CustomField $field */
            if ($field->position !== $position) {
                $field->moveTo($position);
            }
        }
    }
}
