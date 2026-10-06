<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Exceptions\EanAlreadyAssigned;
use SpeedPuzzling\Web\Exceptions\InvalidEan;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Query\FindPuzzlesByExactEan;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\Ean;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Linking policy (docs/features/multiscan/README.md §6):
 *  - puzzle has no code yet      → written immediately, plus an already-approved change request as the audit row
 *  - puzzle has a different code → nothing written, a pending change request proposes the union for a moderator
 *  - puzzle already carries it   → no-op
 *  - another puzzle carries it   → refused (hidden puzzles included; the user gets a generic message)
 */
#[AsMessageHandler]
readonly final class LinkEanToPuzzleHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private FindPuzzlesByExactEan $findPuzzlesByExactEan,
        private ClockInterface $clock,
        private SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    /**
     * @throws InvalidEan
     * @throws EanAlreadyAssigned
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(LinkEanToPuzzle $message): void
    {
        $ean = Ean::from($message->ean);

        // The rule of every form (EanList::invalidCodes()): the Ravensburger misread keeps a valid check digit
        if (EanList::isAcceptedNewCode($message->ean) === false) {
            throw new InvalidEan();
        }
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        // A puzzle a competition keeps secret is nobody's to use but its organisers' (SecretPuzzleAccess) - also while
        // only its picture is hidden: its codes would give the box away
        $this->secretPuzzleAccess->assertPuzzleUsableBy($puzzle, $message->playerId, alsoWhileImageHidden: true);
        $player = $this->playerRepository->get($message->playerId);

        $owners = $this->findPuzzlesByExactEan->ids($ean);

        if (in_array($puzzle->id->toString(), $owners, true)) {
            return;
        }

        if ($owners !== []) {
            foreach ($owners as $ownerId) {
                if ($this->secretPuzzleAccess->isHiddenFromPlayer($ownerId, $message->playerId, alsoWhileImageHidden: true) === false) {
                    throw new EanAlreadyAssigned();
                }
            }

            // Only puzzles a competition keeps secret from this player carry the code: "already assigned" would tell
            // that a secret box has it - answered like any other failure
            throw new PuzzleNotFound();
        }

        $now = $this->clock->now();
        $currentEans = $puzzle->eans();
        $scanned = EanList::fromStored($ean->normalized());

        if ($currentEans->isEmpty()) {
            // The audit keeps what was stored - a placeholder like "-" too
            $storedEan = $puzzle->ean;
            $puzzle->updateProductIdentifiers($scanned, $puzzle->brandCodes());

            $audit = $this->changeRequest($puzzle, $player, $now, $ean->normalized(), originalEan: $storedEan);
            $audit->approve($player, $now);
            $this->puzzleChangeRequestRepository->save($audit);

            return;
        }

        $proposedEan = (string) $currentEans->union($scanned)->toStored();

        if ($this->puzzleChangeRequestRepository->findPendingEanProposal($puzzle, $proposedEan) !== null) {
            return;
        }

        $this->puzzleChangeRequestRepository->save(
            $this->changeRequest($puzzle, $player, $now, $proposedEan, originalEan: $puzzle->ean),
        );
    }

    private function changeRequest(
        Puzzle $puzzle,
        Player $player,
        DateTimeImmutable $now,
        string $proposedEan,
        null|string $originalEan,
    ): PuzzleChangeRequest {
        return new PuzzleChangeRequest(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            reporter: $player,
            submittedAt: $now,
            proposedEan: $proposedEan,
            originalName: $puzzle->name,
            originalManufacturerId: $puzzle->manufacturer?->id,
            originalPiecesCount: $puzzle->piecesCount,
            originalEan: $originalEan,
            originalIdentificationNumber: $puzzle->identificationNumber,
            originalImage: $puzzle->image,
            originalAlternativeNames: $puzzle->alternativeNames,
            originalNameLanguage: $puzzle->nameLanguage,
        );
    }
}
