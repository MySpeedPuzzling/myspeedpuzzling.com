<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Exceptions\CustomFieldLimitReached;
use SpeedPuzzling\Web\Exceptions\CustomFieldNameTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCustomFieldDefinition;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddCustomField;
use SpeedPuzzling\Web\Repository\CustomFieldRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\CustomFieldDefinition;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddCustomFieldHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private CustomFieldRepository $customFieldRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws InvalidCustomFieldDefinition
     * @throws CustomFieldLimitReached
     * @throws CustomFieldNameTaken
     */
    public function __invoke(AddCustomField $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        // The limit and the name are checked against what is there: two adds of one player take turns
        $this->idLock->lockUntilCommit($player->id);

        $fields = $this->customFieldRepository->ofPlayer($player);
        $activeCount = count(array_filter($fields, static fn (CustomField $field): bool => $field->isArchived() === false));

        // A ready-made field is there once: tapped again it stays, tapped while archived it comes back
        if ($message->standard !== null) {
            foreach ($fields as $field) {
                if ($field->standardKey !== $message->standard) {
                    continue;
                }

                if ($field->isArchived()) {
                    if ($activeCount >= CustomField::MAX_ACTIVE_PER_PLAYER) {
                        throw new CustomFieldLimitReached();
                    }

                    $field->restore();
                }

                return;
            }
        }

        // Everything checked before anything is created
        $definition = $message->standard === null
            ? CustomFieldDefinition::fromInput($message->name, $message->type, $message->choices, $message->forResults, $message->forCollectionItems)
            : null;

        if ($activeCount >= CustomField::MAX_ACTIVE_PER_PLAYER || count($fields) >= CustomField::MAX_PER_PLAYER) {
            throw new CustomFieldLimitReached();
        }

        $nameKey = CustomFieldDefinition::nameKey($definition !== null ? $definition->name : $message->name);
        $lastPosition = -1;

        foreach ($fields as $field) {
            if (CustomFieldDefinition::nameKey($field->name) === $nameKey) {
                throw new CustomFieldNameTaken();
            }

            $lastPosition = max($lastPosition, $field->position);
        }

        $id = Uuid::fromString($message->customFieldId);
        $now = $this->clock->now();

        if ($message->standard !== null) {
            $this->customFieldRepository->save(CustomField::readyMade($id, $player, $message->standard, $lastPosition + 1, $now));

            return;
        }

        $this->customFieldRepository->save(CustomField::ownOf($id, $player, $definition, $lastPosition + 1, $now));
    }
}
