<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

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
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $player = $this->playerRepository->get($message->playerId);

        $owners = $this->findPuzzlesByExactEan->ids($ean);

        if (in_array($puzzle->id->toString(), $owners, true)) {
            return;
        }

        if ($owners !== []) {
            throw new EanAlreadyAssigned();
        }

        $now = $this->clock->now();
        $currentEan = $puzzle->ean !== null ? trim($puzzle->ean) : '';

        if ($currentEan === '') {
            $puzzle->updateProductIdentifiers(
                ean: $ean->normalized(),
                identificationNumber: $puzzle->identificationNumber,
            );

            $audit = $this->changeRequest($puzzle, $player, $now, $ean->normalized(), originalEan: null);
            $audit->approve($player, $now);
            $this->puzzleChangeRequestRepository->save($audit);

            return;
        }

        $proposedEan = $currentEan . ', ' . $ean->normalized();

        if ($this->puzzleChangeRequestRepository->findPendingEanProposal($puzzle, $proposedEan) !== null) {
            return;
        }

        $this->puzzleChangeRequestRepository->save(
            $this->changeRequest($puzzle, $player, $now, $proposedEan, originalEan: $currentEan),
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
        );
    }
}
