<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bare locale roots (/en, /es, ...) used to hard-404 - permanently
 * redirect them to the localized homepage.
 */
final class LocaleRootRedirectController extends AbstractController
{
    #[Route(
        path: '/{_locale}',
        name: 'locale_root',
        requirements: ['_locale' => 'en|es|ja|fr|de'],
    )]
    public function __invoke(Request $request): Response
    {
        return $this->redirectToRoute('homepage', [
            '_locale' => $request->getLocale(),
        ], Response::HTTP_MOVED_PERMANENTLY);
    }
}
