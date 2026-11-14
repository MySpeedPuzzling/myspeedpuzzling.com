<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Exceptions\CustomFieldLimitReached;
use SpeedPuzzling\Web\Exceptions\CustomFieldNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ArchiveCustomField;
use SpeedPuzzling\Web\Repository\CustomFieldRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ArchiveCustomFieldHandler
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
     * @throws CustomFieldNotFound
     * @throws CustomFieldLimitReached
     */
    public function __invoke(ArchiveCustomField $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $this->idLock->lockUntilCommit($player->id);

        $field = $this->customFieldRepository->getOwnedBy($message->customFieldId, $player);

        if ($message->archived) {
            // The values stay where they are - only the field is hidden
            $field->archive($this->clock->now());

            return;
        }

        if ($field->isArchived() === false) {
            return;
        }

        $activeCount = count(array_filter(
            $this->customFieldRepository->ofPlayer($player),
            static fn (CustomField $other): bool => $other->isArchived() === false,
        ));

        if ($activeCount >= CustomField::MAX_ACTIVE_PER_PLAYER) {
            throw new CustomFieldLimitReached();
        }

        $field->restore();
    }
}
