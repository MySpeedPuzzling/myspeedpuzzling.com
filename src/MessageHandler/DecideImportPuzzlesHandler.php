<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\ImportPuzzleDecision;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleChoiceRefused;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Repository\DataImportPuzzleRepository;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\ImportPuzzleDecisionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzleBulkConfirm;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\ImportPuzzleDecidedHow;
use SpeedPuzzling\Web\Value\ImportPuzzleTier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Under the import's lock (docs/features/data-import/pairing.md "Decisions"). **Everything is checked before anything
 * is written** - a refused message leaves no half of itself behind:
 *
 * - the import is the player's and is being set up, every puzzle named is one of it;
 * - three-way: each puzzle's `decision_version` is still what the page saw;
 * - a chosen catalogue puzzle exists and may be a candidate for this player (GetImportPuzzleCandidates::sqlCandidate()
 *   - what the player finds by searching passes the same check);
 * - a catalogue puzzle whose piece count is not the file's (more than one apart) is chosen alone, never in a group,
 *   and only with the player's explicit yes (`confirmedOtherPieces`);
 * - **several catalogue puzzles at once** ("Yes, these N are right", `bulkConfirm`) only where each is the same
 *   puzzle with less proof (ImportPuzzleBulkConfirm::isPartA()): one possible puzzle, found by the whole name, the
 *   barcode or the brand's code, neither the brand nor the piece count differs, it still waits and the player
 *   never said a word against it (a match taken off, a remembered "not this puzzle"). Anything else
 *   in the set refuses the whole message - it is confirmed on its own, after a look (guided-flow.md §6.1).
 *
 * A decision taken back on a puzzle the rules never looked at (it arrived decided as in an earlier file) makes it
 * nobody's and asks for the pairing's run: it goes to the pile its own look-up gives.
 *
 * Then each puzzle takes the decision as the player's, and the player's remembered decision for its key is written
 * (a pairing taken back is remembered as "not this puzzle"; anything else taken back is forgotten). The counters of "where the rules end" move by what changed - the
 * next run of the pairing counts them afresh (GetImportPairing::counters() is the same rule in SQL).
 */
#[AsMessageHandler]
readonly final class DecideImportPuzzlesHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportPuzzleRepository $dataImportPuzzleRepository,
        private ImportPuzzleDecisionRepository $decisionRepository,
        private PuzzleRepository $puzzleRepository,
        private GetImportPuzzleCandidates $candidates,
        private IdLock $idLock,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws DataImportNotFound
     * @throws ImportPuzzleDecidedMeanwhile
     * @throws ImportPuzzleChoiceRefused
     */
    public function __invoke(DecideImportPuzzles $message): void
    {
        if (Uuid::isValid($message->importId) === false) {
            throw new DataImportNotFound();
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);

        if ($import->isBeingSetUp() === false) {
            throw new DataImportNotFound();
        }

        if ($message->decisions === []) {
            return;
        }

        $now = $this->clock->now();
        $importPuzzles = $this->dataImportPuzzleRepository->ofImportByIds(
            $import,
            array_map(static fn ($decision): string => $decision->importPuzzleId, $message->decisions),
        );

        $chosenIds = [];
        $stale = [];

        foreach ($message->decisions as $decision) {
            $importPuzzle = $importPuzzles[strtolower($decision->importPuzzleId)] ?? null;

            if ($importPuzzle === null) {
                throw new ImportPuzzleChoiceRefused(ImportPuzzleChoiceRefused::UNKNOWN_PUZZLE, $decision->importPuzzleId);
            }

            if ($decision->choice === ImportPuzzleChoice::NotThisPuzzle || ($decision->choice === ImportPuzzleChoice::Catalogue) !== ($decision->puzzleId !== null)) {
                throw new ImportPuzzleChoiceRefused(ImportPuzzleChoiceRefused::INVALID, $decision->importPuzzleId);
            }

            if ($importPuzzle->isDecisionVersion($decision->seenVersion) === false) {
                $stale[] = $decision->importPuzzleId;
            }

            if ($decision->puzzleId !== null) {
                $chosenIds[] = strtolower($decision->puzzleId);
            }
        }

        if ($stale !== []) {
            throw new ImportPuzzleDecidedMeanwhile($stale);
        }

        $chosenIds = array_values(array_unique(array_filter($chosenIds, Uuid::isValid(...))));
        $choosable = $this->candidates->choosable($chosenIds, $message->playerId, $now);

        foreach ($message->decisions as $decision) {
            if ($decision->puzzleId === null) {
                continue;
            }

            $pieces = $choosable[strtolower($decision->puzzleId)] ?? null;

            if ($pieces === null) {
                throw new ImportPuzzleChoiceRefused(ImportPuzzleChoiceRefused::NOT_CHOOSABLE, $decision->importPuzzleId);
            }

            $said = $importPuzzles[strtolower($decision->importPuzzleId)]->piecesCount;

            if ($said !== null && abs($pieces - $said) > 1 && count($message->decisions) > 1) {
                throw new ImportPuzzleChoiceRefused(ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_IN_GROUP, $decision->importPuzzleId);
            }

            // Alone, and only after the player's yes to the question - asked by the server, not by a script
            if ($said !== null && abs($pieces - $said) > 1 && $decision->confirmedOtherPieces === false) {
                throw new ImportPuzzleChoiceRefused(ImportPuzzleChoiceRefused::OTHER_PIECE_COUNT_UNCONFIRMED, $decision->importPuzzleId);
            }
        }

        $puzzles = $this->puzzleRepository->findByIds($chosenIds);
        $keys = [];

        foreach ($message->decisions as $decision) {
            $keys[] = $importPuzzles[strtolower($decision->importPuzzleId)]->puzzleKey;
        }

        $remembered = $this->decisionRepository->ofPlayerByKeys($import->player, array_values(array_unique($keys)));
        $confirmedTogether = $message->bulkConfirm || count(array_filter($message->decisions, static fn ($decision): bool => $decision->puzzleId !== null)) > 1;

        foreach ($confirmedTogether ? $message->decisions : [] as $decision) {
            if ($decision->puzzleId === null && $message->bulkConfirm === false) {
                continue;
            }

            $puzzle = $decision->puzzleId === null ? null : ($puzzles[strtolower($decision->puzzleId)] ?? null);

            // "These are right" names a puzzle for each - and only one that needs no closer look
            if ($puzzle === null || ImportPuzzleBulkConfirm::isPartA($importPuzzles[strtolower($decision->importPuzzleId)], $puzzle, $remembered[$importPuzzles[strtolower($decision->importPuzzleId)]->puzzleKey] ?? null) === false) {
                throw new ImportPuzzleChoiceRefused(ImportPuzzleChoiceRefused::NEEDS_A_CLOSER_LOOK, $decision->importPuzzleId);
            }
        }

        // Checked - from here on nothing refuses
        $lookUp = false;
        $moved = [];

        foreach ($importPuzzles as $importPuzzle) {
            foreach (self::counted($importPuzzle) as $counter) {
                $moved[$counter] = ($moved[$counter] ?? 0) - 1;
            }
        }

        foreach ($message->decisions as $decision) {
            $importPuzzle = $importPuzzles[strtolower($decision->importPuzzleId)];
            $key = $importPuzzle->puzzleKey;

            if ($decision->choice === ImportPuzzleChoice::Undecided) {
                // A pairing taken back is remembered as "not this puzzle": the import's puzzle may be built anew (a
                // sheet left out and taken in again), and the rules must not make the same pairing again
                $takenBack = $importPuzzle->choice === ImportPuzzleChoice::Catalogue ? $importPuzzle->puzzle : null;

                if ($importPuzzle->tier === null) {
                    // "Matched like last time", taken back: the rules never looked at this puzzle - they do now
                    $importPuzzle->decisionTakenBackBeforeTheRulesLooked($now);
                    $lookUp = true;
                } else {
                    $importPuzzle->decisionTakenBack($now);
                }

                if ($takenBack !== null && isset($remembered[$key])) {
                    $remembered[$key]->decide(ImportPuzzleChoice::NotThisPuzzle, $takenBack, $now);
                } elseif ($takenBack !== null) {
                    $remembered[$key] = new ImportPuzzleDecision(Uuid::uuid7(), $import->player, $key, ImportPuzzleChoice::NotThisPuzzle, $takenBack, $now);
                    $this->decisionRepository->save($remembered[$key]);
                } elseif (isset($remembered[$key])) {
                    $this->decisionRepository->delete($remembered[$key]);
                    unset($remembered[$key]);
                }

                continue;
            }

            $puzzle = $decision->puzzleId === null ? null : $puzzles[strtolower($decision->puzzleId)];
            $importPuzzle->decidedByPlayer($decision->choice, $puzzle, $now);

            if (isset($remembered[$key])) {
                $remembered[$key]->decide($decision->choice, $puzzle, $now);
            } else {
                $remembered[$key] = new ImportPuzzleDecision(Uuid::uuid7(), $import->player, $key, $decision->choice, $puzzle, $now);
                $this->decisionRepository->save($remembered[$key]);
            }
        }

        foreach ($importPuzzles as $importPuzzle) {
            foreach (self::counted($importPuzzle) as $counter) {
                $moved[$counter] = ($moved[$counter] ?? 0) + 1;
            }
        }

        $import->pairingCountersMoved($moved);
        $import->workedOn($now);

        if ($lookUp) {
            // The pairing is due for the puzzle nobody looked at: its run is queued after this is committed, and
            // until it comes to rest the import says "not paired" - so its pages follow it and the safety net asks
            // again if the message is lost (docs/features/data-import/pairing.md "A remembered decision taken back")
            $import->pairingDue($now);
            $this->messageBus->dispatch(new BuildImportPuzzles($message->importId), [new DispatchAfterCurrentBusStamp()]);
        }
    }

    /**
     * The counters of "where the rules end" a puzzle is counted in - GetImportPairing::counters()'s rule, for one
     * puzzle: `remembered`, or `player.<name>`.
     *
     * @return list<string>
     */
    private static function counted(DataImportPuzzle $puzzle): array
    {
        if ($puzzle->decidedHow === ImportPuzzleDecidedHow::Remembered) {
            return ['remembered'];
        }

        if ($puzzle->decidedHow !== ImportPuzzleDecidedHow::Player) {
            return [];
        }

        $isCandidate = false;

        foreach ($puzzle->candidates as $candidate) {
            $isCandidate = $isCandidate || (is_array($candidate) && $puzzle->puzzle !== null && ($candidate['puzzleId'] ?? null) === $puzzle->puzzle->id->toString());
        }

        $isCatalogue = $puzzle->choice === ImportPuzzleChoice::Catalogue;
        $suggested = $puzzle->tier === ImportPuzzleTier::Probably || $puzzle->tier === ImportPuzzleTier::Choose;
        $counted = ['player.decided'];

        if ($puzzle->tier === ImportPuzzleTier::Found && ($isCatalogue === false || $isCandidate === false)) {
            $counted[] = 'player.foundOverruled';
        }

        if ($suggested && ($puzzle->choice === ImportPuzzleChoice::Unlisted || $puzzle->choice === ImportPuzzleChoice::LeftOut)) {
            $counted[] = 'player.noneOfThese';
        }

        if ($suggested && $isCatalogue && $isCandidate === false) {
            $counted[] = 'player.pickedAnother';
        }

        if ($puzzle->tier === ImportPuzzleTier::None && $isCatalogue) {
            $counted[] = 'player.foundByHand';
        }

        return $counted;
    }
}
