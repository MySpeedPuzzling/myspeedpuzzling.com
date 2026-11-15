<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportPileAnswer;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\StartImportPuzzlePileAnswer;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Repository\DataImportPileAnswerRepository;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzlePile;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportStatus;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An answer for a whole pile is asked for. **Under the import's lock**, everything checked before it is written
 * down - so of two answers sent at the same moment (two tabs, a double tap) exactly one starts:
 *
 * 1. the import is the player's (else "not found") and is being set up, the pile has such an answer;
 * 2. nothing works by itself: no sheet is read, no puzzle is looked up - the pile is whole;
 * 3. the import stands as the screen showed it (`shownRevision`);
 * 4. **no other answer runs for this pile** - one that does answers "busy", and nothing is started beside it;
 * 5. something waits in the pile.
 *
 * Nothing is decided here: the answer is gone through by ImportPuzzlePile, a chunk a turn.
 */
#[AsMessageHandler]
readonly final class StartImportPuzzlePileAnswerHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportPileAnswerRepository $answerRepository,
        private GetImportPuzzleScreen $getImportPuzzleScreen,
        private GetDataImports $getDataImports,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{state: string, waiting: int}
     *
     * @throws DataImportNotFound
     */
    public function __invoke(StartImportPuzzlePileAnswer $message): array
    {
        if (Uuid::isValid($message->answerId) === false || Uuid::isValid($message->importId) === false) {
            throw new DataImportNotFound();
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);
        $choice = ImportPuzzleChoice::tryFrom($message->choice);

        if ($choice === null || in_array($choice, ImportPuzzlePile::CHOICES[$message->pile] ?? [], true) === false || $import->isBeingSetUp() === false) {
            return ['state' => ImportPuzzlePile::REFUSED, 'waiting' => 0];
        }

        $overview = $this->getImportPuzzleScreen->overview($message->importId, $message->playerId);
        $progress = $this->getDataImports->progressOfPlayer($message->importId, $message->playerId);
        $waiting = $overview->groups[$message->pile]['open'];

        // The pile is not whole while puzzles are still looked up: one answer would miss those that arrive
        if ($progress->status !== DataImportStatus::SettingUp->value || $progress->sheetsWorking > 0 || $progress->pairing['running'] || $overview->paired === false || $overview->searching > 0) {
            return ['state' => ImportPuzzlePile::STILL_LOOKING, 'waiting' => $waiting];
        }

        if (hash_equals($progress->puzzlesRevision(), $message->shownRevision) === false) {
            return ['state' => ImportPuzzlePile::CHANGED, 'waiting' => $waiting];
        }

        // One answer at a time per pile: "keep" and "don't import" side by side would split the pile
        if ($this->answerRepository->findOpen($import, $message->pile) !== null || $this->answerRepository->find($message->answerId) !== null) {
            return ['state' => ImportPuzzlePile::BUSY, 'waiting' => $waiting];
        }

        if ($waiting === 0) {
            return ['state' => ImportPuzzlePile::NOTHING, 'waiting' => 0];
        }

        $now = $this->clock->now();
        $this->answerRepository->save(new DataImportPileAnswer(
            id: Uuid::fromString($message->answerId),
            import: $import,
            pile: $message->pile,
            choice: $choice->value,
            revision: $progress->puzzlesRevision(),
            puzzlesTotal: $waiting,
            startedAt: $now,
        ));
        $import->workedOn($now);

        return ['state' => ImportPuzzlePile::STARTED, 'waiting' => $waiting];
    }
}
