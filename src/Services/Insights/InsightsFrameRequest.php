<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsFrameContext;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\InsightsLazyPart;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The rules every lazy Insights part shares with the page (InsightsController, the Insights live component): the
 * signed-in player's own data only (the URL carries no player), a 404 while InsightsFeature hides the page, membership
 * with trials counted, the predictions setting, the player's time zone for the filters, and the page's filters from
 * the URL - only the ones the part reads, a free player's members-only values dropped (InsightsCriteria).
 */
readonly final class InsightsFrameRequest
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private InsightsFeature $insightsFeature,
        private RouterInterface $router,
    ) {
    }

    /**
     * @throws NotFoundHttpException while the viewer may not see Insights
     */
    public function context(Request $request, InsightsLazyPart $part): InsightsFrameContext
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || $this->insightsFeature->isVisibleTo($profile) === false) {
            throw new NotFoundHttpException();
        }

        $isMember = $profile->activeMembership;
        $criteria = InsightsCriteria::fromUserInput(
            $part->filterQuery($request->query->all()),
            $isMember,
            InsightsPageLoader::zone($profile->timezone) !== null,
        );

        return new InsightsFrameContext(
            part: $part,
            profile: $profile,
            isMember: $isMember,
            predictionsAllowed: $profile->timePredictionsOptedOut === false,
            criteria: $criteria,
            frameId: $part->frameId($criteria),
        );
    }

    /**
     * A frame's URL opened on its own (no Turbo-Frame header: a new tab, a crawler) goes to its section of the page
     */
    public function pageRedirect(Request $request, InsightsFrameContext $context): null|RedirectResponse
    {
        if ($request->headers->has('Turbo-Frame')) {
            return null;
        }

        $url = $this->router->generate('insights', $context->part->query($context->criteria), UrlGeneratorInterface::ABSOLUTE_PATH);

        return new RedirectResponse($url . '#' . $context->part->anchors()[0], Response::HTTP_FOUND);
    }

    /**
     * Personal: never indexed, never cached by anybody but the page cache
     */
    public static function privateResponse(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
