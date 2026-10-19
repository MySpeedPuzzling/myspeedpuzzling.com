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
 * Lazy frame of the Insights page: section 13, pairs & teams - pairs and teams apart, never mixed with solo; people the
 * viewer may not see are "Hidden Puzzler" (PairsTeamsSynergy gives them no player id).
 */
final class InsightsPairsFrameController extends AbstractController
{
    public function __construct(
        readonly private InsightsFrameRequest $insightsFrameRequest,
        readonly private InsightsFrames $insightsFrames,
    ) {
    }

    #[Route(
        path: '/{_locale}/insights/pairs-and-teams',
        name: 'insights_pairs',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $context = $this->insightsFrameRequest->context($request, InsightsLazyPart::Pairs);
        $redirect = $this->insightsFrameRequest->pageRedirect($request, $context);

        if ($redirect !== null) {
            return $redirect;
        }

        return InsightsFrameRequest::privateResponse($this->render('insights/frames/pairs.html.twig', [
            'context' => $context,
            'synergy' => $this->insightsFrames->pairs($context->profile->playerId, $context->criteria),
        ]));
    }
}
