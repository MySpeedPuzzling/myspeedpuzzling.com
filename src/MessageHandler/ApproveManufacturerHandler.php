<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ManufacturerAlreadyApproved;
use SpeedPuzzling\Web\Exceptions\ManufacturerNameTaken;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ApproveManufacturer;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CatalogueStatsProvider;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ApproveManufacturerHandler
{
    public function __construct(
        private ManufacturerRepository $manufacturerRepository,
        private PlayerRepository $playerRepository,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private CatalogueStatsProvider $catalogueStatsProvider,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     * @throws PlayerNotFound
     * @throws ManufacturerAlreadyApproved
     * @throws ManufacturerNameTaken
     */
    public function __invoke(ApproveManufacturer $message): void
    {
        $reviewer = $this->playerRepository->get($message->reviewerId);
        $manufacturer = $this->manufacturerRepository->get($message->manufacturerId);

        if ($manufacturer->approved) {
            throw new ManufacturerAlreadyApproved();
        }

        $newName = trim($message->name ?? '');
        $newName = $newName === '' || $newName === $manufacturer->name ? null : $newName;

        if ($this->manufacturerRepository->approvedNameExists($newName ?? $manufacturer->name, [$manufacturer->id])) {
            throw new ManufacturerNameTaken(sprintf(
                'An approved brand is already called "%s" - merge this one into it instead.',
                $newName ?? $manufacturer->name,
            ));
        }

        // --- validated, now apply ---

        $previousName = $manufacturer->name;

        if ($newName !== null) {
            $manufacturer->name = $newName;
        }

        $manufacturer->approved = true;

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::BrandApproved,
            decidedBy: $reviewer,
            source: $message->decisionSource,
            manufacturerId: $manufacturer->id,
            note: $message->decisionNote,
            details: [
                'manufacturerName' => $manufacturer->name,
                'previousName' => $newName !== null ? $previousName : null,
            ],
        );

        if ($manufacturer->slug !== null) {
            $this->catalogueStatsProvider->forgetBrands([$manufacturer->slug]);
        }
    }
}
