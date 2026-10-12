<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Navigation\NavigationSearchIndex;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "Pages & settings" list of the page search (docs/features/navigation README §4.12 "Search", MENU-03): every Menu
 * row and search-only entry in the locale with its audience tag - the same for everybody (the browser filters it by
 * the page's data-viewer-audience; moderator and admin entries are rendered into their pages instead), so it is public
 * and cached for a day. Fetched on the first focus of a search field, never inlined into the page. No statement, no
 * session, no cookie: the `stateless` firewall (config/packages/security.php) - a signed-in request gets the same
 * public answer.
 *
 * The page asks for `?v=<version>` (NavigationSearchIndex::version()): a page rendered by another deploy (both run for
 * a moment during a blue-green rollout) asks for a version this one does not have - it gets this list, cached briefly.
 */
final class NavigationSearchIndexController extends AbstractController
{
    public const int MAX_AGE = 86400;

    // Another deploy's version: a minute, so the right list is fetched again soon
    private const int OTHER_VERSION_MAX_AGE = 60;

    public function __construct(
        readonly private NavigationSearchIndex $navigationSearchIndex,
    ) {
    }

    #[Route(
        path: '/{_locale}/nav-search.json',
        name: 'nav_search_index',
        requirements: ['_locale' => 'cs|de|en|es|fr|ja'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $locale = $request->getLocale();
        $asked = $request->query->get('v');

        $response = new Response($this->navigationSearchIndex->json($locale), Response::HTTP_OK, [
            'Content-Type' => 'application/json',
        ]);
        $response->setPublic();
        $response->setMaxAge($asked === null || $asked === $this->navigationSearchIndex->version($locale) ? self::MAX_AGE : self::OTHER_VERSION_MAX_AGE);
        // The same list for everybody: the session listener must not turn it private
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
