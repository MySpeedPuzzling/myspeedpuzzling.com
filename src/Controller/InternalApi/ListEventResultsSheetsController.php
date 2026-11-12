<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Query\GetEventResultsSheets;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every registered results sheet and what became of each tab: status, problems, the event, how many results.
 */
final class ListEventResultsSheetsController extends AbstractController
{
    public function __construct(
        private readonly GetEventResultsSheets $getEventResultsSheets,
    ) {
    }

    #[Route(
        path: '/internal-api/event-results-sheets',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['sheets' => $this->getEventResultsSheets->all()]);
    }
}
