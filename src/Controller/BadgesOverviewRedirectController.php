<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catalog page moved from "badges" to "achievements" URLs (locked terminology:
 * the system is "Achievements", a badge is only the medallion graphic). Old links
 * keep working via permanent redirect.
 */
final class BadgesOverviewRedirectController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/odznaky',
            'en' => '/en/badges',
            'es' => '/es/insignias',
            'ja' => '/ja/バッジ',
            'fr' => '/fr/badges',
            'de' => '/de/abzeichen',
        ],
        name: 'badges_overview_legacy',
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('badges_overview', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
