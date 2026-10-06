<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddApprovedPuzzle;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddApprovedPuzzleHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private ManufacturerResolver $manufacturerResolver,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws ManufacturerNotFound
     */
    public function __invoke(AddApprovedPuzzle $message): void
    {
        $reviewer = $this->playerRepository->get($message->reviewerId);
        $now = $this->clock->now();

        // The same brand rule as the add form: an id, or a typed name found among every brand, else a new brand
        $manufacturer = $this->manufacturerResolver->resolve($message->brand, $reviewer, $now);

        $puzzle = new Puzzle(
            $message->puzzleId,
            $message->piecesCount,
            $message->name,
            approved: false,
            manufacturer: $manufacturer,
            alternativeNames: $message->alternativeNames,
            addedByUser: $reviewer,
            addedAt: $now,
            brandCodes: $message->brandCodes,
            eans: $message->eans,
            nameLanguage: $message->nameLanguage,
        );
        $puzzle->approve($reviewer, $now);

        $this->entityManager->persist($puzzle);

        // A catalogue decision like any approval - it shows in the puzzle's history
        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::PuzzleApproved,
            decidedBy: $reviewer,
            source: MergeDecisionSource::InternalApi,
            puzzleId: $puzzle->id,
            puzzleName: $puzzle->name,
            manufacturerId: $manufacturer->id,
            note: 'Added approved, without a photo, through the internal API.',
            details: [
                'brandChoice' => PuzzleApprovalBrandChoice::Keep->value,
                'addedApproved' => true,
            ],
        );
    }
}
