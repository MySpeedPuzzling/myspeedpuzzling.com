<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\PuzzleFilterOptions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Brand/tag options for the /puzzle search selects, fetched on first
 * dropdown focus instead of being rendered into the initial HTML.
 * Public data, identical for every visitor - cacheable end to end.
 */
final class PuzzleSearchFilterOptionsController extends AbstractController
{
    public function __construct(
        readonly private PuzzleFilterOptions $puzzleFilterOptions,
    ) {
    }

    #[Route(
        path: '/puzzle-search-filter-options',
        name: 'puzzle_search_filter_options',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse($this->puzzleFilterOptions->all());
        $response->setPublic();
        $response->setMaxAge(3600);
        // Same data for every visitor: stop the session listener from
        // downgrading the response to private/no-cache.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
