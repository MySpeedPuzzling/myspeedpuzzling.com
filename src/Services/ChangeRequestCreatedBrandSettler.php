<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\ManufacturerSlugRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

/**
 * What the review of a change request does with the brand the proposal created (PuzzleChangeRequest::$createdManufacturerName)
 * - a player typed a brand no brand matched, usually the right spelling of a misspelled one:
 *
 * - the puzzle ended up in it: the moderator accepted the name, so the brand is approved. When the brand the puzzle had
 *   is left without puzzles, the proposal was a rename - it is merged into the new brand (ManufacturerMerger: its slug
 *   redirects, its logo and EAN prefixes are kept)
 * - it was rejected, or the moderator picked another brand: nobody uses the new brand, so it is deleted
 *
 * Every step records its decision, like the approval queue does. Called by the approve and the reject handler after
 * the puzzle has its final brand - nothing here is flushed yet, so puzzle counts are taken from the loaded objects.
 */
readonly final class ChangeRequestCreatedBrandSettler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private ManufacturerRepository $manufacturerRepository,
        private ManufacturerSlugRedirectRepository $manufacturerSlugRedirectRepository,
        private ManufacturerMerger $manufacturerMerger,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private CatalogueStatsProvider $catalogueStatsProvider,
    ) {
    }

    /**
     * @param null|Manufacturer $brandBefore The puzzle's brand before the review (null on a rejection - nothing moved)
     */
    public function settle(
        PuzzleChangeRequest $changeRequest,
        null|Manufacturer $brandBefore,
        Player $reviewer,
        MergeDecisionSource $source = MergeDecisionSource::AdminUi,
    ): void {
        $created = $changeRequest->proposedManufacturer;

        if ($changeRequest->createdManufacturerName === null || $created === null) {
            return;
        }

        $puzzle = $changeRequest->puzzle;

        if ($changeRequest->status === PuzzleReportStatus::Approved && $puzzle->manufacturer === $created) {
            $this->renameWhenLeftEmpty($changeRequest, $brandBefore, $created, $reviewer, $source);
            $this->approve($changeRequest, $created, $reviewer, $source);

            return;
        }

        $this->deleteWhenUnused($changeRequest, $created, $reviewer, $source);
    }

    private function renameWhenLeftEmpty(
        PuzzleChangeRequest $changeRequest,
        null|Manufacturer $brandBefore,
        Manufacturer $created,
        Player $reviewer,
        MergeDecisionSource $source,
    ): void {
        if ($brandBefore === null || $brandBefore === $created || $this->puzzlesOf($brandBefore) > 0) {
            return;
        }

        $merged = $this->manufacturerMerger->merge($brandBefore, $created);

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::BrandMerged,
            decidedBy: $reviewer,
            source: $source,
            puzzleId: $changeRequest->puzzle->id,
            puzzleName: $changeRequest->puzzle->name,
            changeRequestId: $changeRequest->id,
            manufacturerId: $created->id,
            note: 'The brand was left without puzzles by a change request proposing a new brand - renamed.',
            details: [
                'mergedManufacturerId' => $brandBefore->id->toString(),
                'mergedManufacturerName' => $brandBefore->name,
                'intoManufacturerName' => $created->name,
            ] + $merged,
        );
    }

    private function approve(PuzzleChangeRequest $changeRequest, Manufacturer $created, Player $reviewer, MergeDecisionSource $source): void
    {
        // A merged approved brand approved it already
        if ($created->approved) {
            return;
        }

        $created->approved = true;

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::BrandApproved,
            decidedBy: $reviewer,
            source: $source,
            puzzleId: $changeRequest->puzzle->id,
            puzzleName: $changeRequest->puzzle->name,
            changeRequestId: $changeRequest->id,
            manufacturerId: $created->id,
            details: ['manufacturerName' => $created->name],
        );
    }

    private function deleteWhenUnused(PuzzleChangeRequest $changeRequest, Manufacturer $created, Player $reviewer, MergeDecisionSource $source): void
    {
        // Somebody else picked it meanwhile (a puzzle added with it, another proposal) - a brand like any other now
        if (
            $created->approved
            || $this->puzzlesOf($created) > 0
            || $this->puzzleChangeRequestRepository->countByProposedManufacturer($created) > 1
            || count($this->manufacturerSlugRedirectRepository->findByManufacturer($created)) > 0
        ) {
            return;
        }

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::BrandDeleted,
            decidedBy: $reviewer,
            source: $source,
            puzzleId: $changeRequest->puzzle->id,
            puzzleName: $changeRequest->puzzle->name,
            changeRequestId: $changeRequest->id,
            manufacturerId: $created->id,
            note: 'Created by a change request that was not applied with it - unused.',
            details: [
                'manufacturerName' => $created->name,
                'manufacturerSlug' => $created->slug,
                'manufacturerApproved' => $created->approved,
                'manufacturerAddedAt' => $created->addedAt?->format(DateTimeInterface::ATOM),
            ],
        );

        $changeRequest->createdManufacturerDeleted();
        $slug = $created->slug;

        $this->manufacturerRepository->delete($created);

        if ($slug !== null) {
            $this->catalogueStatsProvider->forgetBrands([$slug]);
        }
    }

    /**
     * The brand's puzzles as the loaded objects have them - a puzzle moved in this request is not flushed yet.
     */
    private function puzzlesOf(Manufacturer $manufacturer): int
    {
        return count(array_filter(
            $this->puzzleRepository->findByManufacturer($manufacturer),
            static fn (Puzzle $puzzle): bool => $puzzle->manufacturer === $manufacturer,
        ));
    }
}
