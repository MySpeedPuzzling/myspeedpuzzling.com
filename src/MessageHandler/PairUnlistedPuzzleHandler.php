<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ImportPuzzleDecision;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\UnlistedPairing;
use SpeedPuzzling\Web\Exceptions\UnlistedPuzzleNotFound;
use SpeedPuzzling\Web\Message\PairUnlistedPuzzle;
use SpeedPuzzling\Web\Message\ProcessUnlistedPairing;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Repository\ImportPuzzleDecisionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "It is this catalogue puzzle" from the request (docs/features/data-import/unlisted-puzzles.md "Pairing"). One
 * transaction, serialized per player with their "Add"s; every check before anything is written:
 *
 * 1. the unlisted puzzle is the player's - else "not found";
 * 2. the same request id answers the same pairing;
 * 3. the import's safety switch: pairing makes results public, so it waits while adding is paused;
 * 4. no pairing or Undo of this unlisted puzzle is at work;
 * 5. the catalogue puzzle is one the player may choose - the candidates' own rule (never a hidden or secret puzzle,
 *    never one waiting for approval unless they added it), checked whatever the page offered;
 * 6. the puzzle was not paired with another catalogue puzzle whose results are still there;
 * 7. the rate limit of "Add" (`data_import_add`);
 * 8. something unlisted is left on it.
 *
 * Then the pairing is recorded, the unlisted results and marks are handed to it, the unlisted puzzle is marked
 * paired, **the player's remembered decision for the puzzle key says this catalogue puzzle** (their next file pairs
 * directly) and the work goes to the `imports` queue. Nothing is converted in the request.
 */
#[AsMessageHandler]
readonly final class PairUnlistedPuzzleHandler
{
    public function __construct(
        private UnlistedPuzzleRepository $unlistedRepository,
        private DataImportSettingsRepository $settingsRepository,
        private GetImportPuzzleCandidates $candidates,
        private PuzzleRedirectRepository $puzzleRedirectRepository,
        private ImportPuzzleDecisionRepository $decisionRepository,
        private EntityManagerInterface $entityManager,
        private RateLimiterFactoryInterface $dataImportAddLimiter,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UnlistedPuzzleNotFound
     */
    public function __invoke(PairUnlistedPuzzle $message): UnlistedPairingOutcome
    {
        if (Uuid::isValid($message->requestId) === false) {
            throw new UnlistedPuzzleNotFound();
        }

        $unlistedPuzzle = $this->unlistedRepository->getOwnedBy($message->unlistedPuzzleId, $message->playerId);
        $player = $unlistedPuzzle->player;
        $playerId = $player->id->toString();
        $sameRequest = $this->unlistedRepository->findPairingByRequestId($message->requestId);

        if ($sameRequest !== null) {
            // Somebody else's request id is nobody's business
            if ($sameRequest->unlistedPuzzle->id->equals($unlistedPuzzle->id) === false) {
                throw new UnlistedPuzzleNotFound();
            }

            return new UnlistedPairingOutcome(UnlistedPairingOutcome::SAME_REQUEST, $sameRequest->id->toString());
        }

        if ($this->settingsRepository->isAddingPaused()) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::PAUSED);
        }

        if ($this->unlistedRepository->workingPairingOf($unlistedPuzzle) !== null) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::ALREADY_RUNNING);
        }

        $now = $this->clock->now();
        $puzzleId = strtolower($message->puzzleId);

        if (Uuid::isValid($puzzleId) === false || isset($this->candidates->choosable([$puzzleId], $playerId, $now)[$puzzleId]) === false) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::NOT_AVAILABLE);
        }

        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);

        if ($puzzle === null) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::NOT_AVAILABLE);
        }

        $results = $this->unlistedRepository->resultsOf($unlistedPuzzle);
        $ownerships = $this->unlistedRepository->ownershipsOf($unlistedPuzzle);
        $waiting = [];
        $somethingIsReal = false;

        foreach ([...$results, ...$ownerships] as $subject) {
            if ($subject->isUnlisted()) {
                $waiting[] = $subject;
            } else {
                $somethingIsReal = true;
            }
        }

        // What is left of a paired puzzle goes to the same catalogue puzzle (a merge since then is followed) - or the
        // pairing is taken back first
        if ($somethingIsReal && $unlistedPuzzle->pairedPuzzleId !== null) {
            $pairedId = $unlistedPuzzle->pairedPuzzleId->toString();
            $pairedNow = $this->puzzleRedirectRepository->findByOldPuzzleId($pairedId)?->survivorPuzzleId->toString() ?? $pairedId;

            if ($pairedNow !== $puzzleId && $pairedId !== $puzzleId) {
                return new UnlistedPairingOutcome(UnlistedPairingOutcome::OTHER_PUZZLE);
            }
        }

        if ($this->dataImportAddLimiter->create($playerId)->consume()->isAccepted() === false) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::RATE_LIMITED);
        }

        if ($waiting === []) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::NOTHING);
        }

        $pairing = new UnlistedPairing(Uuid::uuid7(), Uuid::fromString($message->requestId), $player, $unlistedPuzzle, $puzzle->id, $now);
        $this->unlistedRepository->savePairing($pairing);

        foreach ($waiting as $subject) {
            $subject->takenByPairing($pairing->id);
        }

        $unlistedPuzzle->pairedWith($puzzle->id, $now);

        // The same puzzle in the player's next file arrives paired
        $remembered = $this->decisionRepository->ofPlayerByKeys($player, [$unlistedPuzzle->puzzleKey])[$unlistedPuzzle->puzzleKey] ?? null;

        if ($remembered !== null) {
            $remembered->decide(ImportPuzzleChoice::Catalogue, $puzzle, $now);
        } else {
            $this->decisionRepository->save(new ImportPuzzleDecision(Uuid::uuid7(), $player, $unlistedPuzzle->puzzleKey, ImportPuzzleChoice::Catalogue, $puzzle, $now));
        }

        // On the `imports` queue; with the Doctrine transport the job is written in this very transaction
        $this->messageBus->dispatch(new ProcessUnlistedPairing($pairing->id->toString()));

        return new UnlistedPairingOutcome(UnlistedPairingOutcome::STARTED, $pairing->id->toString());
    }
}
