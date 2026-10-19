<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Insights;

use SpeedPuzzling\Web\Services\Insights\InsightsFrameRequest;
use SpeedPuzzling\Web\Services\Insights\InsightsFrames;
use SpeedPuzzling\Web\Value\InsightsLazyPart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lazy frame of the Insights page: "What from my shelf suits me?" (ShelfPicks) - members; a free player gets the lock
 * with the number of unsolved shelf puzzles. A pick is its expected time, so time predictions switched off = the note
 * and the setting instead.
 */
final class InsightsShelfFrameController extends AbstractController
{
    public function __construct(
        readonly private InsightsFrameRequest $insightsFrameRequest,
        readonly private InsightsFrames $insightsFrames,
    ) {
    }

    #[Route(
        path: '/{_locale}/insights/shelf',
        name: 'insights_shelf',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $context = $this->insightsFrameRequest->context($request, InsightsLazyPart::Shelf);
        $redirect = $this->insightsFrameRequest->pageRedirect($request, $context);

        if ($redirect !== null) {
            return $redirect;
        }

        return InsightsFrameRequest::privateResponse($this->render('insights/frames/shelf.html.twig', [
            'context' => $context,
            'shelf' => $this->insightsFrames->shelf($context->profile->playerId, $context->criteria, $context->isMember, $context->predictionsAllowed),
        ]));
    }
}
