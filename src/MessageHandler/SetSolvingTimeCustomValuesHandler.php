<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SolvingTimeCustomValues;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Message\SetSolvingTimeCustomValues;
use SpeedPuzzling\Web\Repository\CustomFieldRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimeCustomValuesRepository;
use SpeedPuzzling\Web\Services\CustomFields\SubmittedValuesChange;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\CustomValues;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes one player's own row of one result and nothing else (docs/features/custom-fields.md): no domain event, no
 * notification, no achievements - the result itself is not touched.
 */
#[AsMessageHandler]
readonly final class SetSolvingTimeCustomValuesHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private CustomFieldRepository $customFieldRepository,
        private SolvingTimeCustomValuesRepository $solvingTimeCustomValuesRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws PlayerNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws InvalidCustomValues
     */
    public function __invoke(SetSolvingTimeCustomValues $message): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($message->solvingTimeId);
        $player = $this->playerRepository->get($message->playerId);

        // Whoever tracked the result or is a registered member of it - their own row only. An imported event result
        // nobody has claimed has no member, a claimed one has its claimers
        if (in_array($player->id->toString(), $solvingTime->memberPlayerIds(), true) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        // Two saves of one player's row of one result take turns - the second finds the first one's row
        $this->idLock->lockUntilCommit(Uuid::uuid5($solvingTime->id, $player->id->toString()));

        $fields = [];

        // Somebody else's field, an archived one, one that is not for results: all the same "no such field"
        foreach ($this->customFieldRepository->ofPlayer($player) as $field) {
            if ($field->isUsableOnResults()) {
                $fields[$field->id->toString()] = $field;
            }
        }

        $row = $this->solvingTimeCustomValuesRepository->find($solvingTime, $player);
        $stored = $row?->values() ?? new CustomValues();

        // Everything checked before anything changes - a refused handler's changes stay in the entity manager
        $values = SubmittedValuesChange::apply($stored, $fields, $message->values);
        $now = $this->clock->now();

        if ($row === null) {
            if ($values->isEmpty() === false) {
                $this->solvingTimeCustomValuesRepository->save(new SolvingTimeCustomValues(Uuid::uuid7(), $solvingTime, $player, $values, $now));
            }

            return;
        }

        if ($values->isEmpty()) {
            $this->solvingTimeCustomValuesRepository->delete($row);

            return;
        }

        if ($values->values !== $stored->values) {
            $row->change($values, $now);
        }
    }
}
