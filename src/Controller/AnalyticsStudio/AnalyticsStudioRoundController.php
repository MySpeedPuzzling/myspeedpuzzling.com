<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioRound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A round's analytics studio by the round's id (docs/features/competitions-management/analytics-studio.md §8, S3): the
 * organiser pages (displays, round stopwatch, online control room) know a round's id but not its event's and series'
 * slugs - they link here at no statement of their own, and this redirects to the studio's URL (one statement:
 * GetAnalyticsStudioRound::byId(), whose public-visibility condition makes a round of an event that is not public, or
 * without a slug, a 404 - the same answer as the page).
 */
final class AnalyticsStudioRoundController extends AbstractController
{
    public function __construct(
        readonly private GetAnalyticsStudioRound $getAnalyticsStudioRound,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/analytics-studio/{roundId}',
            'en' => '/en/analytics-studio/{roundId}',
            'es' => '/es/analytics-studio/{roundId}',
            'ja' => '/ja/analytics-studio/{roundId}',
            'fr' => '/fr/analytics-studio/{roundId}',
            'de' => '/de/analytics-studio/{roundId}',
        ],
        name: 'analytics_studio_round',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->getAnalyticsStudioRound->byId($roundId);

        if ($round->seriesSlug !== null && $round->eventSlug !== null) {
            $url = $this->generateUrl('edition_analytics_studio', ['seriesSlug' => $round->seriesSlug, 'editionSlug' => $round->eventSlug, 'roundSlug' => $round->slug]);
        } elseif ($round->eventSlug !== null) {
            $url = $this->generateUrl('event_analytics_studio', ['slug' => $round->eventSlug, 'roundSlug' => $round->slug]);
        } else {
            throw new AnalyticsStudioNotFound();
        }

        $response = $this->redirect($url);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
