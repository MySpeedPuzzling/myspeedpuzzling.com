<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\DeleteManufacturer;
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
 * Deletes a brand nothing uses any more. Refused (409) while a puzzle uses it, a change
 * request proposes it or a merged brand's slug redirects to it - merge it instead.
 */
final class DeleteManufacturerController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/manufacturers/{manufacturerId}/delete',
        requirements: ['manufacturerId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(string $manufacturerId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the deletion to.',
            );
        }

        $body = InternalApiJsonBody::parse($request);

        $this->messageBus->dispatch(new DeleteManufacturer(
            manufacturerId: $manufacturerId,
            reviewerId: $this->reviewerPlayerId,
            decisionSource: MergeDecisionSource::InternalApi,
            decisionNote: InternalApiJsonBody::optionalString($body, 'decisionNote'),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
