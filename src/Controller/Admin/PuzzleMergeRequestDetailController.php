<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\FormData\PuzzleMergeReviewFormData;
use SpeedPuzzling\Web\FormData\PuzzleNamesFormData;
use SpeedPuzzling\Web\FormType\PuzzleMergeReviewFormType;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleMergeRequests;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Results\PuzzleMergeRequestOverview;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\PuzzleRecord;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\NamedPuzzle;
use SpeedPuzzling\Web\Value\PuzzleMergeNames;
use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @phpstan-type MergedData array{
 *     ean: string,
 *     identification_number: string,
 *     pieces_counts: list<int>,
 *     images: array<string, string>,
 *     manufacturers: array<string, string>,
 *     survivor_puzzle_id: null|string,
 *     total_solved_times: int,
 *     differs: array{name: bool, brand: bool, pieces: bool, ean: bool, code: bool},
 * }
 *
 * The review of a merge request: the reported puzzles side by side, which one keeps its address, and the merged
 * puzzle's record - every name of all of them in the names editor, started from the union with the languages the
 * reporter gave (PuzzleMergeNames). The form posts back here, so a refused one comes back with what was typed; it
 * carries every puzzle's record version, so a puzzle saved in between refuses the merge.
 */
final class PuzzleMergeRequestDetailController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleMergeRequests $getPuzzleMergeRequests,
        private readonly GetPuzzleOverview $getPuzzleOverview,
        private readonly GetPuzzleRecord $getPuzzleRecord,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-merge-requests/{id}',
        name: 'admin_puzzle_merge_request_detail',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request, string $id): Response
    {
        $mergeRequest = $this->getPuzzleMergeRequests->byId($id) ?? throw new PuzzleMergeRequestNotFound();

        // The reported puzzles still there - an earlier merge may have deleted some
        $puzzles = [];
        $records = [];

        foreach ($mergeRequest->reportedDuplicatePuzzleIds as $puzzleId) {
            try {
                $puzzle = $this->getPuzzleOverview->byId($puzzleId);
            } catch (PuzzleNotFound) {
                continue;
            }

            // The stored record - the image of a puzzle under embargo included - for the record version
            $record = $this->getPuzzleRecord->byId($puzzleId);

            if ($record !== null) {
                $puzzles[] = $puzzle;
                $records[$puzzle->puzzleId] = $record;
            }
        }

        $mergedData = $this->collectMergedData($puzzles);

        $parameters = [
            'request' => $mergeRequest,
            'puzzles' => $puzzles,
            'records' => $records,
            'merged_data' => $mergedData,
            'reported_languages' => $this->reportedLanguageLabels($mergeRequest),
            'form' => null,
        ];

        if ($mergeRequest->status !== PuzzleReportStatus::Pending || $puzzles === []) {
            return $this->render('admin/puzzle_merge_request_detail.html.twig', $parameters);
        }

        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();

        $survivorId = $mergedData['survivor_puzzle_id'];
        assert($survivorId !== null);

        $form = $this->createForm(PuzzleMergeReviewFormType::class, $this->prefilledReview($mergeRequest, $records, $survivorId, $mergedData), [
            'puzzle_ids' => array_keys($records),
            'image_puzzle_ids' => array_keys($mergedData['images']),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->survivorPuzzleId !== null && $data->piecesCount !== null);

            try {
                $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
                    mergeRequestId: $mergeRequest->id,
                    reviewerId: $player->playerId,
                    survivorPuzzleId: $data->survivorPuzzleId,
                    mergedName: $data->names->mainTitle(),
                    mergedEan: $data->ean,
                    mergedIdentificationNumber: $data->identificationNumber,
                    mergedPiecesCount: $data->piecesCount,
                    mergedManufacturerId: $data->manufacturerId,
                    selectedImagePuzzleId: $data->selectedImagePuzzleId,
                    decisionSource: MergeDecisionSource::AdminUi,
                    decisionNote: $data->decisionNote !== null && trim($data->decisionNote) !== '' ? trim($data->decisionNote) : null,
                    mergedNameLanguage: $data->names->nameLanguage,
                    mergedAlternativeNames: $data->names->toPuzzleNames(),
                    recordVersions: $data->recordVersions,
                ));

                $this->addFlash('success', $this->translator->trans('admin.puzzle_merge_request.approved'));

                // Merges started from the approval queue go back there
                $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'));

                if ($returnUrl !== null) {
                    return $this->redirect((string) $returnUrl);
                }

                return $this->redirectToRoute('admin_puzzle_merge_requests');
            } catch (PuzzleChangedMeanwhile) {
                $form->addError(new FormError($this->translator->trans('puzzle_names.record_changed_meanwhile')));
            } catch (InvalidPuzzleValues $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/puzzle_merge_request_detail.html.twig', [
            'form' => $form,
        ] + $parameters);
    }

    /**
     * The merged puzzle as the review starts: the survivor's record with every name of all the puzzles, and the
     * codes of all of them.
     *
     * @param array<string, PuzzleRecord> $records
     * @param MergedData $mergedData
     */
    private function prefilledReview(
        PuzzleMergeRequestOverview $mergeRequest,
        array $records,
        string $survivorId,
        array $mergedData,
    ): PuzzleMergeReviewFormData {
        $survivor = $records[$survivorId];

        $mergeNames = new PuzzleMergeNames(
            self::namedPuzzle($survivor),
            array_values(array_map(
                self::namedPuzzle(...),
                array_filter($records, static fn (PuzzleRecord $record): bool => $record->puzzleId !== $survivorId),
            )),
            $mergeRequest->reportedNameLanguages,
        );
        $names = $mergeNames->forReview();

        $data = new PuzzleMergeReviewFormData(
            PuzzleNamesFormData::fromNames($names->name, $names->nameLanguage, $names->alternativeNames),
        );
        $data->survivorPuzzleId = $survivorId;
        $data->piecesCount = $survivor->piecesCount;
        $data->manufacturerId = $survivor->manufacturerId;

        $data->ean = $mergedData['ean'] !== '' ? $mergedData['ean'] : null;
        $data->identificationNumber = $mergedData['identification_number'] !== '' ? $mergedData['identification_number'] : null;
        $data->selectedImagePuzzleId = array_key_exists($survivorId, $mergedData['images']) ? $survivorId : array_key_first($mergedData['images']);

        $data->recordVersions = array_map(static fn (PuzzleRecord $record): string => $record->recordVersion(), $records);

        return $data;
    }

    private static function namedPuzzle(PuzzleRecord $record): NamedPuzzle
    {
        return new NamedPuzzle($record->puzzleId, $record->name, $record->nameLanguage, $record->alternativeNames);
    }

    /**
     * @return array<string, string> Puzzle id => "Czech"
     */
    private function reportedLanguageLabels(PuzzleMergeRequestOverview $mergeRequest): array
    {
        return array_map(
            static fn (string $language): string => PuzzleNameLanguageChoices::label($language, 'en'),
            $mergeRequest->reportedNameLanguages,
        );
    }

    /**
     * @param array<PuzzleOverview> $puzzles
     *
     * @return MergedData
     */
    private function collectMergedData(array $puzzles): array
    {
        $eans = [];
        $identificationNumbers = [];
        $pieceCounts = [];
        $images = [];
        $manufacturers = [];

        // First pass: find the survivor puzzle (the one with most solving times)
        $survivorPuzzle = null;
        $maxSolvedTimes = -1;

        foreach ($puzzles as $puzzle) {
            if ($puzzle->solvedTimes > $maxSolvedTimes) {
                $maxSolvedTimes = $puzzle->solvedTimes;
                $survivorPuzzle = $puzzle;
            }
        }

        // Second pass: collect all values for merging
        foreach ($puzzles as $puzzle) {
            if ($puzzle->puzzleEan !== null && $puzzle->puzzleEan !== '') {
                $eans[] = $puzzle->puzzleEan;
            }
            if ($puzzle->puzzleIdentificationNumber !== null && $puzzle->puzzleIdentificationNumber !== '') {
                $identificationNumbers[] = $puzzle->puzzleIdentificationNumber;
            }
            $pieceCounts[$puzzle->piecesCount] = $puzzle->piecesCount;
            if ($puzzle->puzzleImage !== null) {
                $images[$puzzle->puzzleId] = $puzzle->puzzleImage;
            }
            $manufacturers[$puzzle->manufacturerId] = $puzzle->manufacturerName;
        }

        return [
            'ean' => implode(', ', array_unique($eans)),
            'identification_number' => implode(', ', array_unique($identificationNumbers)),
            'pieces_counts' => array_values($pieceCounts),
            'images' => $images,
            'manufacturers' => $manufacturers,
            'survivor_puzzle_id' => $survivorPuzzle?->puzzleId,
            'total_solved_times' => array_sum(array_map(static fn (PuzzleOverview $puzzle): int => $puzzle->solvedTimes, $puzzles)),
            // What the review page highlights as different between the reported puzzles
            'differs' => [
                'name' => self::differs($puzzles, static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleName),
                'brand' => self::differs($puzzles, static fn (PuzzleOverview $puzzle): string => $puzzle->manufacturerId),
                'pieces' => self::differs($puzzles, static fn (PuzzleOverview $puzzle): int => $puzzle->piecesCount),
                'ean' => self::differs($puzzles, static fn (PuzzleOverview $puzzle): null|string => $puzzle->puzzleEan),
                'code' => self::differs($puzzles, static fn (PuzzleOverview $puzzle): null|string => $puzzle->puzzleIdentificationNumber),
            ],
        ];
    }

    /**
     * @param array<PuzzleOverview> $puzzles
     * @param callable(PuzzleOverview): (null|string|int) $value
     */
    private static function differs(array $puzzles, callable $value): bool
    {
        return count(array_unique(array_map(
            static fn (PuzzleOverview $puzzle): string => (string) $value($puzzle),
            $puzzles,
        ))) > 1;
    }
}
