<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportPileAnswer;
use SpeedPuzzling\Web\Message\RecordImportPuzzlePileAnswer;
use SpeedPuzzling\Web\Repository\DataImportPileAnswerRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Under the answer's lock: what a turn did is added to the answer, its sign of life is moved - and with `endedAs`
 * the answer ends. An answer that has ended is left as it ended (a late turn, the sweeper and a turn side by side):
 * its numbers are not counted twice and it is never opened again.
 */
#[AsMessageHandler]
readonly final class RecordImportPuzzlePileAnswerHandler
{
    public function __construct(
        private DataImportPileAnswerRepository $answerRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordImportPuzzlePileAnswer $message): void
    {
        if (Uuid::isValid($message->answerId) === false) {
            return;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->answerId));
        $answer = $this->answerRepository->find($message->answerId);

        if ($answer === null || $answer->isOpen() === false) {
            return;
        }

        $now = $this->clock->now();
        $cursor = $message->lastPuzzleId !== null && Uuid::isValid($message->lastPuzzleId) ? Uuid::fromString($message->lastPuzzleId) : null;
        $answer->turnDone(max(0, $message->decided), max(0, $message->skipped), $cursor, $now);

        if (in_array($message->endedAs, [DataImportPileAnswer::DONE, DataImportPileAnswer::CHANGED, DataImportPileAnswer::FAILED], true)) {
            $answer->ended($message->endedAs, $now);
        }
    }
}
