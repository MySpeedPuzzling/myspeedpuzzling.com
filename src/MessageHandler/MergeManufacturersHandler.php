<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Exceptions\InvalidManufacturerMerge;
use SpeedPuzzling\Web\Exceptions\ManufacturerNameTaken;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\MergeManufacturers;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ManufacturerMerger;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Folds duplicate brands into the survivor and records one brand_merged decision
 * per merged brand (docs/features/brand-duplicates.md).
 *
 * Everything is validated before the first change: a handler that throws after
 * mutating still has its changes flushed by a later flush in the same request.
 */
#[AsMessageHandler]
readonly final class MergeManufacturersHandler
{
    public function __construct(
        private ManufacturerRepository $manufacturerRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerMerger $manufacturerMerger,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     * @throws PlayerNotFound
     * @throws InvalidManufacturerMerge
     * @throws ManufacturerNameTaken
     */
    public function __invoke(MergeManufacturers $message): void
    {
        $reviewer = $this->playerRepository->get($message->reviewerId);
        $survivor = $this->manufacturerRepository->get($message->survivorManufacturerId);

        /** @var array<string, Manufacturer> $duplicates */
        $duplicates = [];

        foreach ($message->mergedManufacturerIds as $manufacturerId) {
            $duplicate = $this->manufacturerRepository->get($manufacturerId);

            if ($duplicate->id->equals($survivor->id)) {
                throw new InvalidManufacturerMerge('The surviving brand cannot be merged into itself.');
            }

            $duplicates[$duplicate->id->toString()] = $duplicate;
        }

        if ($duplicates === []) {
            throw new InvalidManufacturerMerge('Name at least one brand to merge.');
        }

        $newName = trim($message->survivorName ?? '');
        $newName = $newName === '' || $newName === $survivor->name ? null : $newName;

        $survivorEndsApproved = $survivor->approved;

        foreach ($duplicates as $duplicate) {
            $survivorEndsApproved = $survivorEndsApproved || $duplicate->approved;
        }

        // Merging must not leave two approved brands of one name behind
        if (
            $survivorEndsApproved
            && $this->manufacturerRepository->approvedNameExists(
                $newName ?? $survivor->name,
                [$survivor->id, ...array_map(static fn (Manufacturer $brand) => $brand->id, array_values($duplicates))],
            )
        ) {
            throw new ManufacturerNameTaken(sprintf(
                'Another approved brand is called "%s" - merge it in this request too.',
                $newName ?? $survivor->name,
            ));
        }

        // --- validated, now apply ---

        $previousName = $survivor->name;

        if ($newName !== null) {
            // The slug stays: it is the address search engines know
            $survivor->name = $newName;
        }

        foreach ($duplicates as $duplicate) {
            $mergedBrand = [
                'mergedManufacturerId' => $duplicate->id->toString(),
                'mergedManufacturerName' => $duplicate->name,
                'mergedManufacturerSlug' => $duplicate->slug,
                'mergedManufacturerApproved' => $duplicate->approved,
            ];

            $merged = $this->manufacturerMerger->merge($duplicate, $survivor);

            $this->puzzleModerationDecisionRecorder->record(
                action: PuzzleModerationAction::BrandMerged,
                decidedBy: $reviewer,
                source: $message->decisionSource,
                manufacturerId: $survivor->id,
                note: $message->decisionNote,
                details: [
                    ...$mergedBrand,
                    'intoManufacturerName' => $survivor->name,
                    'intoManufacturerPreviousName' => $newName !== null ? $previousName : null,
                    'movedPuzzles' => $merged['movedPuzzles'],
                    'movedChangeRequests' => $merged['movedChangeRequests'],
                    'repointedChangeRequestOriginals' => $merged['repointedChangeRequestOriginals'],
                    'redirectedSlugs' => $merged['redirectedSlugs'],
                    'approvedByMerge' => $merged['approvedByMerge'],
                    'decisionConfidence' => $message->decisionConfidence?->value,
                ],
            );
        }
    }
}
