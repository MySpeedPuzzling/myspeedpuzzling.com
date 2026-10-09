<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Query\GetAdminSeries;
use SpeedPuzzling\Web\Results\AdminSeries;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every series - approved, pending, rejected or a draft - by name, optionally searched by name, slug or shortcut (`q`)
 * and narrowed by `status` (the approval state; `draft` = drafts, whatever their approval).
 */
final class ListSeriesController extends AbstractController
{
    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 100;

    private const array STATUSES = ['all', 'approved', 'pending', 'rejected', 'draft'];

    public function __construct(
        private readonly GetAdminSeries $getAdminSeries,
    ) {
    }

    #[Route(
        path: '/internal-api/series',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = min(max($request->query->getInt('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $offset = max($request->query->getInt('offset'), 0);
        $search = $request->query->getString('q');
        $search = trim($search) !== '' ? $search : null;
        $status = $request->query->getString('status', 'all');

        if (in_array($status, self::STATUSES, true) === false) {
            throw new BadRequestHttpException(sprintf('"status" must be one of: %s.', implode(', ', self::STATUSES)));
        }

        return new JsonResponse([
            'total' => $this->getAdminSeries->count($search, $status),
            'limit' => $limit,
            'offset' => $offset,
            'series' => array_map(
                static fn (AdminSeries $series): array => $series->toArray(),
                $this->getAdminSeries->search($search, $status, $limit, $offset),
            ),
        ]);
    }
}
