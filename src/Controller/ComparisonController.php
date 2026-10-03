<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The compare page (docs/features/player-comparison.md). Placeholder so entry points can link to the route while the
 * page is being built.
 */
final class ComparisonController extends AbstractController
{
    #[Route(
        path: '/{_locale}/compare',
        name: 'comparison',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        return new Response('');
    }
}
