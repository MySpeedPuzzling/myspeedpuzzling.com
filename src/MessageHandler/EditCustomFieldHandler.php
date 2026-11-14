<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CustomFieldNameTaken;
use SpeedPuzzling\Web\Exceptions\CustomFieldNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidCustomFieldDefinition;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditCustomField;
use SpeedPuzzling\Web\Repository\CustomFieldRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\CustomFieldDefinition;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCustomFieldHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private CustomFieldRepository $customFieldRepository,
        private IdLock $idLock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws CustomFieldNotFound
     * @throws InvalidCustomFieldDefinition
     * @throws CustomFieldNameTaken
     */
    public function __invoke(EditCustomField $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $this->idLock->lockUntilCommit($player->id);

        $field = $this->customFieldRepository->getOwnedBy($message->customFieldId, $player);

        // Its name follows the page language and its type and choices mean the same for everybody
        if ($field->isReadyMade()) {
            throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::READY_MADE);
        }

        // Everything checked before anything changes - a refused handler's changes stay in the entity manager
        $definition = CustomFieldDefinition::fromInput($message->name, $field->type, $message->choices, $message->forResults, $message->forCollectionItems);
        $nameKey = CustomFieldDefinition::nameKey($definition->name);

        foreach ($this->customFieldRepository->ofPlayer($player) as $other) {
            if ($other->id->equals($field->id) === false && CustomFieldDefinition::nameKey($other->name) === $nameKey) {
                throw new CustomFieldNameTaken();
            }
        }

        $field->redefine($definition);
    }
}
