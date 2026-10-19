<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Insights;

use SpeedPuzzling\Web\Services\Insights\InsightsFrameRequest;
use SpeedPuzzling\Web\Services\Insights\InsightsFrames;
use SpeedPuzzling\Web\Services\Insights\InsightsFramesChartsFactory;
use SpeedPuzzling\Web\Value\InsightsLazyPart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lazy frame of the Insights page: section 11, the re-solve radar - nothing but personal predictions, so a player who
 * switched them off gets the note and the link to the setting instead.
 */
final class InsightsResolveFrameController extends AbstractController
{
    public function __construct(
        readonly private InsightsFrameRequest $insightsFrameRequest,
        readonly private InsightsFrames $insightsFrames,
        readonly private InsightsFramesChartsFactory $chartsFactory,
    ) {
    }

    #[Route(
        path: '/{_locale}/insights/re-solve',
        name: 'insights_resolve',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $context = $this->insightsFrameRequest->context($request, InsightsLazyPart::Resolve);
        $redirect = $this->insightsFrameRequest->pageRedirect($request, $context);

        if ($redirect !== null) {
            return $redirect;
        }

        $radar = $this->insightsFrames->resolve($context->profile->playerId, $context->criteria, $context->predictionsAllowed);

        return InsightsFrameRequest::privateResponse($this->render('insights/frames/resolve.html.twig', [
            'context' => $context,
            'radar' => $radar,
            'chart' => $context->isMember && $radar !== null ? $this->chartsFactory->resolve($radar) : null,
        ]));
    }
}
