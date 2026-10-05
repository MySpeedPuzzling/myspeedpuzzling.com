<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleMergeRequests;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Approves a puzzle merge, recording who decided it and why.
 *
 * A merge deletes puzzles and moves solving times onto the survivor, so the
 * handler writes a full before/after snapshot to the audit trail first. The note
 * and confidence supplied here are stored with it - they are what make a merge
 * decided in bulk reviewable afterwards.
 */
final class ApprovePuzzleMergeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleMergeRequests $getPuzzleMergeRequests,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-merge-requests/{mergeRequestId}/approve',
        requirements: ['mergeRequestId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(string $mergeRequestId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the merge to.',
            );
        }

        $body = InternalApiJsonBody::parse($request);

        $survivorPuzzleId = strtolower(InternalApiJsonBody::requiredString($body, 'survivorPuzzleId'));
        $selectedImagePuzzleId = InternalApiJsonBody::optionalString($body, 'selectedImagePuzzleId');
        $mergedName = InternalApiJsonBody::requiredString($body, 'mergedName');
        $mergedEans = InternalApiJsonBody::optionalCodeList($body, 'mergedEan');
        $mergedBrandCodes = InternalApiJsonBody::optionalCodeList($body, 'mergedIdentificationNumber');

        if (
            ($mergedEans !== null && EanList::fromInputs($mergedEans)->fitsColumn() === false)
            || ($mergedBrandCodes !== null && BrandCodeList::fromInputs($mergedBrandCodes)->fitsColumn() === false)
        ) {
            throw new BadRequestHttpException('"mergedEan" and "mergedIdentificationNumber" can hold at most 255 characters each, written as a list.');
        }
        $mergedPiecesCount = $body['mergedPiecesCount'] ?? null;

        if (is_int($mergedPiecesCount) === false || $mergedPiecesCount <= 0) {
            throw new BadRequestHttpException('"mergedPiecesCount" must be a positive integer.');
        }

        foreach (['survivorPuzzleId' => $survivorPuzzleId, 'selectedImagePuzzleId' => $selectedImagePuzzleId] as $field => $puzzleId) {
            if ($puzzleId !== null && Uuid::isValid($puzzleId) === false) {
                throw new BadRequestHttpException(sprintf('"%s" must be an id.', $field));
            }
        }

        // The survivor is one of the reported puzzles - any other id would merge (and delete) all of them into it
        $reportedPuzzleIds = $this->getPuzzleMergeRequests->reportedPuzzleIdsOf($mergeRequestId) ?? throw new PuzzleMergeRequestNotFound();

        if (in_array($survivorPuzzleId, $reportedPuzzleIds, true) === false) {
            throw new BadRequestHttpException(sprintf(
                '"survivorPuzzleId" must be one of the reported puzzles: %s.',
                implode(', ', $reportedPuzzleIds),
            ));
        }

        try {
            $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                reviewerId: $this->reviewerPlayerId,
                survivorPuzzleId: $survivorPuzzleId,
                mergedName: $mergedName,
                // Optional, a list or a comma-separated string: the codes of every merged puzzle are added either way
                mergedEans: $mergedEans !== null ? EanList::fromInputs($mergedEans) : null,
                mergedBrandCodes: $mergedBrandCodes !== null ? BrandCodeList::fromInputs($mergedBrandCodes) : null,
                mergedPiecesCount: $mergedPiecesCount,
                mergedManufacturerId: InternalApiJsonBody::optionalString($body, 'mergedManufacturerId'),
                selectedImagePuzzleId: $selectedImagePuzzleId !== null ? strtolower($selectedImagePuzzleId) : null,
                decisionSource: MergeDecisionSource::InternalApi,
                decisionConfidence: InternalApiJsonBody::confidence($body),
                decisionNote: InternalApiJsonBody::optionalString($body, 'decisionNote'),
                // Optional: left out, the language the puzzles know for mergedName; null = English or not known
                mergedNameLanguage: InternalApiJsonBody::optionalLanguageTag($body, 'mergedNameLanguage'),
                // Optional: without it every name of the puzzles stays (with the reporter's languages)
                mergedAlternativeNames: InternalApiJsonBody::optionalPuzzleNames($body, 'mergedAlternativeNames'),
                // Optional: the candidates' recordVersion as read from the queue - a puzzle changed since refuses the merge
                recordVersions: InternalApiJsonBody::recordVersions($body, 'recordVersions'),
            ));
        } catch (PuzzleChangedMeanwhile) {
            return new JsonResponse(
                ['error' => 'A puzzle of the merge changed after its recordVersion was read - nothing was merged. Read the queue again and decide again.'],
                Response::HTTP_CONFLICT,
            );
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
