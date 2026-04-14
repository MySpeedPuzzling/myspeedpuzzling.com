<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\UpdateFeatureRequestStatus;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class MarkFeatureRequestInProgressController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/internal-api/feature-requests/{featureRequestId}/mark-in-progress',
        methods: ['POST'],
    )]
    public function __invoke(string $featureRequestId, Request $request): Response
    {
        $body = InternalApiJsonBody::parse($request);

        $githubUrl = $body['githubUrl'] ?? null;
        $adminComment = $body['adminComment'] ?? null;

        $this->messageBus->dispatch(new UpdateFeatureRequestStatus(
            featureRequestId: $featureRequestId,
            status: FeatureRequestStatus::InProgress,
            githubUrl: is_string($githubUrl) ? $githubUrl : null,
            adminComment: is_string($adminComment) ? $adminComment : null,
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
