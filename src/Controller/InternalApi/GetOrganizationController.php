<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One organization by id or slug: its fields, approval and draft state, its team, its series and its one-time events.
 */
final class GetOrganizationController extends AbstractController
{
    public function __construct(
        private readonly GetAdminOrganizations $getAdminOrganizations,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations/{idOrSlug}',
        requirements: ['idOrSlug' => '[^/]+'],
        methods: ['GET'],
    )]
    public function __invoke(string $idOrSlug): JsonResponse
    {
        return new JsonResponse($this->getAdminOrganizations->detail($idOrSlug)->toArray());
    }
}
