<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Query\GetAdminSeries;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One series by id or slug: its fields, organization, approval and draft state, maintainers and every edition (with the
 * competition list's fields - ids, slugs, dates, state, round / result / participant counts).
 */
final class GetSeriesController extends AbstractController
{
    public function __construct(
        private readonly GetAdminSeries $getAdminSeries,
    ) {
    }

    #[Route(
        path: '/internal-api/series/{idOrSlug}',
        requirements: ['idOrSlug' => '[^/]+'],
        methods: ['GET'],
    )]
    public function __invoke(string $idOrSlug): JsonResponse
    {
        return new JsonResponse($this->getAdminSeries->detail($idOrSlug)->toArray());
    }
}
