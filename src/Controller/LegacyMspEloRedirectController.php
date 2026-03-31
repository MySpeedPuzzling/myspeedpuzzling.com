<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegacyMspEloRedirectController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/msp-elo',
            'en' => '/en/msp-elo',
            'es' => '/es/msp-elo',
            'ja' => '/ja/msp-elo',
            'fr' => '/fr/msp-elo',
            'de' => '/de/msp-elo',
        ],
        name: 'legacy_msp_elo_ladder',
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('msp_rating_ladder', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
