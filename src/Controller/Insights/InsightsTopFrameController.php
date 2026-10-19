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
 * Lazy frame of the Insights page (docs/features/insights/page.md "Lazy frames"): the top row's "Try next" and "MSP
 * rating" finding cards - the rating model and the shelf picks are too heavy for the first response, so they load
 * here (eager: the frame sits in the findings grid) from the cache entries the rating and shelf frames share.
 */
final class InsightsTopFrameController extends AbstractController
{
    public function __construct(
        readonly private InsightsFrameRequest $insightsFrameRequest,
        readonly private InsightsFrames $insightsFrames,
    ) {
    }

    #[Route(
        path: '/{_locale}/insights/top-findings',
        name: 'insights_top',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $context = $this->insightsFrameRequest->context($request, InsightsLazyPart::Top);
        $redirect = $this->insightsFrameRequest->pageRedirect($request, $context);

        if ($redirect !== null) {
            return $redirect;
        }

        return InsightsFrameRequest::privateResponse($this->render('insights/frames/top.html.twig', [
            'context' => $context,
            'top' => $this->insightsFrames->top($context->profile->playerId, $context->criteria, $context->isMember, $context->predictionsAllowed),
        ]));
    }
}
