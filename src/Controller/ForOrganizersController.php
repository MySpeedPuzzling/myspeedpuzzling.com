<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ForOrganizersController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pro-organizatory',
            'en' => '/en/for-organizers',
            'es' => '/es/para-organizadores',
            'ja' => '/ja/主催者向け',
            'fr' => '/fr/pour-organisateurs',
            'de' => '/de/fuer-veranstalter',
        ],
        name: 'for_organizers',
    )]
    public function __invoke(): Response
    {
        return $this->render('for-organizers.html.twig');
    }
}
