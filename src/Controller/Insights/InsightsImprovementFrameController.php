<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Insights;

use SpeedPuzzling\Web\Results\Insights\ImprovementCurveSize;
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
 * Lazy frame of the Insights page: section 15, the improvement curve (pace history) and the rating over time
 * (player_rating_snapshot) - members; a free player gets the lock with counts.
 */
final class InsightsImprovementFrameController extends AbstractController
{
    public function __construct(
        readonly private InsightsFrameRequest $insightsFrameRequest,
        readonly private InsightsFrames $insightsFrames,
        readonly private InsightsFramesChartsFactory $chartsFactory,
    ) {
    }

    #[Route(
        path: '/{_locale}/insights/improvement',
        name: 'insights_improvement',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $context = $this->insightsFrameRequest->context($request, InsightsLazyPart::Improvement);
        $redirect = $this->insightsFrameRequest->pageRedirect($request, $context);

        if ($redirect !== null) {
            return $redirect;
        }

        $frame = $this->insightsFrames->improvement($context->profile->playerId, $context->criteria, $context->isMember);
        $main = $frame->curve->sizes[0] ?? null;
        // Private players have no MSP rating (rankings are public-only) - the rating section says the same
        $ratingPrivate = $context->profile->isPrivateProfile;
        $ratingSeries = $ratingPrivate ? null : ($frame->ratingOverTime?->series[0] ?? null);

        return InsightsFrameRequest::privateResponse($this->render('insights/frames/improvement.html.twig', [
            'context' => $context,
            'frame' => $frame,
            'main' => $main,
            'others' => array_slice($frame->curve->sizes, 1),
            'rating_series' => $context->isMember ? $ratingSeries : null,
            'rating_private' => $ratingPrivate,
            'curve_chart' => $context->isMember && $main instanceof ImprovementCurveSize ? $this->chartsFactory->improvement($main) : null,
            'rating_chart' => $context->isMember && $ratingSeries !== null ? $this->chartsFactory->ratingOverTime($ratingSeries) : null,
        ]));
    }
}
