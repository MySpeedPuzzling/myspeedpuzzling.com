<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeInterface;
use SpeedPuzzling\Web\Exceptions\ManufacturerInUse;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\DeleteManufacturer;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\ManufacturerSlugRedirectRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\CatalogueStatsProvider;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes an empty brand and records a brand_deleted decision - the only trace of it afterwards.
 * Refused while a puzzle uses it, a change request proposes it (its foreign key would quietly become
 * null) or a merged brand's slug redirects to it (the redirect would cascade away): those are a
 * merge's job, which moves them. No redirect for its own slug - there is nothing to send it to.
 */
#[AsMessageHandler]
readonly final class DeleteManufacturerHandler
{
    public function __construct(
        private ManufacturerRepository $manufacturerRepository,
        private PuzzleRepository $puzzleRepository,
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private ManufacturerSlugRedirectRepository $manufacturerSlugRedirectRepository,
        private PlayerRepository $playerRepository,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private CatalogueStatsProvider $catalogueStatsProvider,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     * @throws PlayerNotFound
     * @throws ManufacturerInUse
     */
    public function __invoke(DeleteManufacturer $message): void
    {
        $reviewer = $this->playerRepository->get($message->reviewerId);
        $manufacturer = $this->manufacturerRepository->get($message->manufacturerId);

        $puzzles = $this->puzzleRepository->countByManufacturer($manufacturer);
        $changeRequests = $this->puzzleChangeRequestRepository->countByProposedManufacturer($manufacturer);
        $redirects = count($this->manufacturerSlugRedirectRepository->findByManufacturer($manufacturer));

        if ($puzzles > 0 || $changeRequests > 0 || $redirects > 0) {
            throw new ManufacturerInUse(sprintf(
                'The brand "%s" is still in use (%d puzzles, %d change requests proposing it, %d merged slugs redirecting to it) - merge it into another brand instead.',
                $manufacturer->name,
                $puzzles,
                $changeRequests,
                $redirects,
            ));
        }

        // --- validated, now apply ---

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::BrandDeleted,
            decidedBy: $reviewer,
            source: $message->decisionSource,
            manufacturerId: $manufacturer->id,
            note: $message->decisionNote,
            details: [
                'manufacturerName' => $manufacturer->name,
                'manufacturerSlug' => $manufacturer->slug,
                'manufacturerApproved' => $manufacturer->approved,
                'manufacturerAddedAt' => $manufacturer->addedAt?->format(DateTimeInterface::ATOM),
            ],
        );

        $slug = $manufacturer->slug;

        $this->manufacturerRepository->delete($manufacturer);

        if ($slug !== null) {
            $this->catalogueStatsProvider->forgetBrands([$slug]);
        }
    }
}
