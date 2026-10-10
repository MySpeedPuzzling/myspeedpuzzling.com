<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FairPlayXpController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/fair-play-xp',
            'en' => '/en/fair-play-xp',
            'es' => '/es/fair-play-xp',
            'ja' => '/ja/フェアプレイxp',
            'fr' => '/fr/fair-play-xp',
            'de' => '/de/fair-play-xp',
        ],
        name: 'xp_fair_play',
    )]
    public function __invoke(): Response
    {
        return $this->render('xp_fair_play.html.twig');
    }
}
