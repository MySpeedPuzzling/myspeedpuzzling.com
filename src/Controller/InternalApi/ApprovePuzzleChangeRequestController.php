<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Approves a puzzle change request ("suggest a change"), applying only the fields named in
 * `selectedFields` - like the admin review, where unticked fields stay unchanged. An empty list
 * approves a proposal that is already satisfied (e.g. a brand fix a brand merge made) without
 * touching the puzzle. The player who proposed it is notified either way.
 */
final class ApprovePuzzleChangeRequestController extends AbstractController
{
    private const array FIELDS = ['name', 'manufacturer', 'piecesCount', 'ean', 'identificationNumber', 'image'];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
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

        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            reviewerId: $this->reviewerPlayerId,
            selectedFields: array_values(array_unique($selectedFields)),
            decisionSource: MergeDecisionSource::InternalApi,
            decisionNote: InternalApiJsonBody::optionalString($body, 'decisionNote'),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
