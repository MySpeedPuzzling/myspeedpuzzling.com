<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
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
 * Approves a puzzle change request ("suggest a change"), applying only the fields named in
 * `selectedFields` as proposed - the rest of the puzzle stays as it is. An empty list
 * approves a proposal that is already satisfied (e.g. a brand fix a brand merge made) without
 * touching the puzzle. The player who proposed it is notified either way.
 *
 * `alternativeNames` and `nameLanguage` in the body correct the proposal before it is applied (a selected field
 * only): the other names as they should end up and the main title's language. The other names are applied as a
 * diff against the list when the request was filed, like the proposal itself. `recordVersion` (optional) is the
 * puzzle's record as the caller read it: a puzzle changed since answers 409 and nothing is applied.
 */
final class ApprovePuzzleChangeRequestController extends AbstractController
{
    private const array FIELDS = ['name', 'nameLanguage', 'alternativeNames', 'manufacturer', 'piecesCount', 'ean', 'identificationNumber', 'image'];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleChangeRequests $getPuzzleChangeRequests,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-change-requests/{changeRequestId}/approve',
        requirements: ['changeRequestId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(string $changeRequestId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the review to.',
            );
        }

        $body = InternalApiJsonBody::parse($request);
        $selectedFields = $body['selectedFields'] ?? null;

        // Required on purpose, even when empty: what gets applied to the puzzle is never implied
        if (is_array($selectedFields) === false || array_is_list($selectedFields) === false) {
            throw new BadRequestHttpException(sprintf(
                '"selectedFields" is required: a list of fields to apply (%s), [] to apply nothing.',
                implode(', ', self::FIELDS),
            ));
        }

        foreach ($selectedFields as $field) {
            if (in_array($field, self::FIELDS, true) === false) {
                throw new BadRequestHttpException(sprintf(
                    '"selectedFields" may contain only: %s.',
                    implode(', ', self::FIELDS),
                ));
            }
        }

        $alternativeNames = InternalApiJsonBody::optionalPuzzleNames($body, 'alternativeNames');
        $nameLanguage = InternalApiJsonBody::optionalLanguageTag($body, 'nameLanguage');

        foreach (['alternativeNames' => $alternativeNames !== null, 'nameLanguage' => $nameLanguage !== false] as $field => $given) {
            if ($given && in_array($field, $selectedFields, true) === false) {
                throw new BadRequestHttpException(sprintf('"%s" corrects a selected field only - add it to "selectedFields".', $field));
            }
        }

        $puzzleId = $this->getPuzzleChangeRequests->puzzleIdOf($changeRequestId) ?? throw new PuzzleChangeRequestNotFound();

        try {
            $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: $puzzleId,
                reviewerId: $this->reviewerPlayerId,
                selectedFields: array_values(array_unique($selectedFields)),
                decisionSource: MergeDecisionSource::InternalApi,
                decisionNote: InternalApiJsonBody::optionalString($body, 'decisionNote'),
                alternativeNamesOverride: $alternativeNames,
                nameLanguageOverride: $nameLanguage,
                // Optional: the puzzle's recordVersion as read before deciding - a puzzle changed since refuses it
                recordVersion: InternalApiJsonBody::optionalString($body, 'recordVersion'),
            ));
        } catch (PuzzleIsStillSecret) {
            return new JsonResponse(
                ['error' => 'A competition keeps a puzzle of this request secret until it is revealed - nothing was approved. Decide after the reveal.'],
                Response::HTTP_CONFLICT,
            );
        } catch (PuzzleChangedMeanwhile) {
            return new JsonResponse(
                ['error' => 'The puzzle changed after its recordVersion was read - nothing was approved. Read it again and decide again.'],
                Response::HTTP_CONFLICT,
            );
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
