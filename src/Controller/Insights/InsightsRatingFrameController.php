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
 * Lazy frame of the Insights page (docs/features/insights/page.md "Lazy frames"): sections 9 (rating opportunities,
 * pending qualification, portfolio strips) and 10 (forecast) from one rating model. The viewer's own data only, a 404
 * while Insights is hidden from them (InsightsFrameRequest).
 */
final class InsightsRatingFrameController extends AbstractController
{
    public function __construct(
        readonly private InsightsFrameRequest $insightsFrameRequest,
        readonly private InsightsFrames $insightsFrames,
        readonly private InsightsFramesChartsFactory $chartsFactory,
    ) {
    }

    #[Route(
        path: '/{_locale}/insights/rating',
        name: 'insights_rating',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $context = $this->insightsFrameRequest->context($request, InsightsLazyPart::Rating);
        $redirect = $this->insightsFrameRequest->pageRedirect($request, $context);

        if ($redirect !== null) {
            return $redirect;
        }

        $frame = $this->insightsFrames->rating($context->profile->playerId, $context->isMember, $context->profile->isPrivateProfile, $context->profile->rankingOptedOut);
        $forecastChart = $context->isMember && $frame->forecast !== null
            ? $this->chartsFactory->forecast($frame->forecast, $context->predictionsAllowed)
            : null;

        return InsightsFrameRequest::privateResponse($this->render('insights/frames/rating.html.twig', [
            'context' => $context,
            'opportunities' => $frame->opportunities,
            'forecast' => $context->isMember ? $frame->forecast : null,
            'forecast_chart' => $forecastChart,
        ]));
    }
}
