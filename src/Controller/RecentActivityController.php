<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RecentActivityController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/nedavna-aktivita',
            'en' => '/en/recent-activity',
            'es' => '/es/actividad-reciente',
            'ja' => '/ja/最近のアクティビティ',
            'fr' => '/fr/activite-recente',
            'de' => '/de/aktuelle-aktivitaet',
        ],
        name: 'recent_activity',
    )]
    public function __invoke(): Response
    {
        return $this->render('recent_activity.html.twig');
    }
}
