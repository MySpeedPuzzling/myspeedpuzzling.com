<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Menu as a page (docs/features/navigation README §4.12, MENU-02a): what a link to the Menu opens without JS, in a
 * new tab, from "Where did it go?", the footer's "All pages" and search results - and the Menu screen of the native
 * app. Skeleton: heading and intro; MENU-01 renders the Menu partial here as the page's content.
 */
final class MenuPageController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/menu',
            'en' => '/en/menu',
            'es' => '/es/menu',
            'ja' => '/ja/メニュー',
            'fr' => '/fr/menu',
            'de' => '/de/menue',
        ],
        name: 'menu',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('navigation/menu_page.html.twig');
    }
}
