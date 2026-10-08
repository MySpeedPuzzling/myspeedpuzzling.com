<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Results\AdminCompetition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every competition - approved, pending, rejected or a draft, standalone or an edition of a series - newest first,
 * optionally searched by name, slug or shortcut (`q`) and narrowed by `status`.
 */
final class ListCompetitionsController extends AbstractController
{
    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 100;

    // The approval state (an approved draft is approved), or `draft`: the competition or its series is a draft
    private const array STATUSES = ['all', 'approved', 'pending', 'rejected', 'draft'];

    public function __construct(
        private readonly GetAdminCompetitions $getAdminCompetitions,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions',
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
            'total' => $this->getAdminCompetitions->count($search, $status),
            'limit' => $limit,
            'offset' => $offset,
            'competitions' => array_map(
                static fn (AdminCompetition $competition): array => $competition->toArray(),
                $this->getAdminCompetitions->search($search, $status, $limit, $offset),
            ),
        ]);
    }
}
