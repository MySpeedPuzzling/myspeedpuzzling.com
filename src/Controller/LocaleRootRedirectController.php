<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The English homepage lives at the domain root "/", so the bare /en root
 * permanently redirects there. All other locale roots (/cs, /de, ...) are
 * real localized homepages handled by HomepageController.
 */
final class LocaleRootRedirectController extends AbstractController
{
    #[Route(
        path: '/en',
        name: 'locale_root',
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('homepage', [
            '_locale' => 'en',
        ], Response::HTTP_MOVED_PERMANENTLY);
    }
}
