<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A personal link without a language (docs/features/online-competitions/README.md §5.4, stream RP): `/compete/{token}`
 * - what the links CSV hands out, so the language of the organiser's page never becomes the participant's. It sends
 * the visitor to their competition page (`compete_page`) in the language of the signed-in player's profile, else the
 * browser's first one the site speaks, else English - as `/p/{puzzleId}` does. Reads nothing of the link: an unknown
 * one gets the friendly 404 of the page it lands on. The token is in the address: never stored by a browser or a
 * proxy, never passed on, never indexed (the route name starts with `compete_` - OnlineTokenRedactor keeps it out of
 * the logs).
 */
final class CompeteLinkController extends AbstractController
{
    /** English first: getPreferredLanguage() falls back to the first entry when nothing matches */
    public const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(path: '/compete/{token}', name: 'compete_link', methods: ['GET'])]
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $locale = $this->retrieveLoggedUserProfile->getProfile()?->locale;

        if (!in_array($locale, self::LOCALES, true)) {
            $locale = $request->getPreferredLanguage(self::LOCALES) ?? 'en';
        }

        $response = $this->redirectToRoute('compete_page', ['token' => $token, '_locale' => $locale]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Robots-Tag', 'noindex');
        // This Cache-Control stands - the session listener must not stamp its own over it for signed-in visitors
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
