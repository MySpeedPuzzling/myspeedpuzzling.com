<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\MergeManufacturers;
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
 * Folds duplicate brands into the one in the URL (docs/features/brand-duplicates.md).
 *
 * Destructive: the merged brands are deleted, their puzzles move over and their
 * slugs answer 301 to the survivor. Each merged brand gets a brand_merged row in
 * puzzle_moderation_decision with the confidence and note supplied here.
 */
final class MergeManufacturersController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/manufacturers/{manufacturerId}/merge',
        requirements: ['manufacturerId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(string $manufacturerId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the merge to.',
            );
        }

        $body = InternalApiJsonBody::parse($request);

        $this->messageBus->dispatch(new MergeManufacturers(
            survivorManufacturerId: $manufacturerId,
            mergedManufacturerIds: InternalApiJsonBody::uuidList($body, 'mergedManufacturerIds', 1),
            reviewerId: $this->reviewerPlayerId,
            decisionSource: MergeDecisionSource::InternalApi,
            survivorName: InternalApiJsonBody::optionalString($body, 'name'),
            decisionConfidence: InternalApiJsonBody::confidence($body),
            decisionNote: InternalApiJsonBody::optionalString($body, 'decisionNote'),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
