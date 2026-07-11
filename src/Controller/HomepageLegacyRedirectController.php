<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The homepage moved from localized slugs (/uvod, /en/home, ...) to
 * per-locale roots (/cs, /, ...) - permanently redirect the old paths.
 */
final class HomepageLegacyRedirectController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/uvod',
            'en' => '/en/home',
            'es' => '/es/inicio',
            'ja' => '/ja/ホーム',
            'fr' => '/fr/accueil',
            'de' => '/de/start',
        ],
        name: 'homepage_legacy',
    )]
    public function __invoke(Request $request): Response
    {
        return $this->redirectToRoute('homepage', [
            '_locale' => $request->getLocale(),
        ], Response::HTTP_MOVED_PERMANENTLY);
    }
}
