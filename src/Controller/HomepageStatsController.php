<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\HomepageStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tiny JSON feed for the homepage live counters - polled every 30s by the
 * count-up Stimulus controller. Public data, identical for every visitor,
 * cacheable end to end.
 */
final class HomepageStatsController extends AbstractController
{
    public function __construct(
        readonly private HomepageStatistics $homepageStatistics,
    ) {
    }

    #[Route(
        path: '/homepage-stats',
        name: 'homepage_stats',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse($this->homepageStatistics->all());
        $response->setPublic();
        $response->setMaxAge(30);
        // Same data for every visitor: stop the session listener from
        // downgrading the response to private/no-cache.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
