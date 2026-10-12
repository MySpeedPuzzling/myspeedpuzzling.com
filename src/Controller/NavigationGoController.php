<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Navigation\MenuBuilder;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Navigation\NavigationViewerFactory;
use SpeedPuzzling\Web\Navigation\RowKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A page of the viewer's own found by the page search (docs/features/navigation MENU-03, R14): nav-search.json is the
 * same for everybody, so entries whose URL names the viewer (Results, Library, Your data, …) link here and this
 * redirects to the viewer's page - the link their Menu row has (MenuBuilder::urlFor()). Signed-in only: a guest is
 * sent to sign in and comes back here (LoginEntryPoint's `?return=`). An id the page search does not list, an entry
 * that is not a page (the Help sheet, the language switch, Log out) or one this viewer may not use is a 404. Costs the
 * user refresh and the profile row, nothing more.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class NavigationGoController extends AbstractController
{
    public function __construct(
        readonly private NavigationRegistry $registry,
        readonly private NavigationViewerFactory $viewerFactory,
        readonly private MenuBuilder $menuBuilder,
    ) {
    }

    #[Route(
        path: '/{_locale}/go/{entryId}',
        name: 'nav_go',
        requirements: ['_locale' => 'cs|de|en|es|fr|ja', 'entryId' => '[a-z0-9_]+'],
        methods: ['GET'],
    )]
    public function __invoke(string $entryId): RedirectResponse
    {
        $entry = $this->registry->byId($entryId);
        $viewer = $this->viewerFactory->current();

        if ($entry === null || !$entry->isInPageSearch() || !in_array($entry->kind, [RowKind::Link, RowKind::Place], true) || !$viewer->sees($entry->visibility)) {
            throw new NotFoundHttpException();
        }

        $url = $this->menuBuilder->urlFor($entry, $viewer);

        // A search-only entry the viewer has no page for (no country for "Your country's leaderboard"): its parent
        $parent = $entry->isSearchOnly() && $entry->phone->parent !== null ? $this->registry->byId($entry->phone->parent) : null;

        if ($url === null && $parent !== null && $viewer->sees($parent->visibility)) {
            $url = $this->menuBuilder->urlFor($parent, $viewer);
        }

        if ($url === null) {
            throw new NotFoundHttpException();
        }

        $response = new RedirectResponse($url);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
