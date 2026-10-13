<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Navigation\NavEntry;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Navigation\NavigationViewerFactory;
use SpeedPuzzling\Web\Services\Community\CommunityPreview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Community landing (docs/features/navigation README §4.9, COM-01a): the place's start page with its three
 * sections - Leaderboards, Puzzlers, Events. Each card's title and icon come from the section's registry entry, so a
 * destination has one name everywhere. COM-01: each card previews its section (CommunityPreview, three statements) -
 * the same rows for every viewer but the players they hide and the star on their favourites; no session, no CSRF: the
 * guests' page is the same for every guest and stays shared-cacheable. Compare follows its registry audience (signed
 * in, review PUZ+COM Q1): a guest's tap would only end on the sign-in page.
 */
final class CommunityController extends AbstractController
{
    /**
     * Card item (the data-nav item and the community_page.<item>.* keys) => registry entry id
     */
    private const array SECTIONS = [
        'leaderboards' => 'leaderboards',
        'puzzlers' => 'puzzlers',
        'events' => 'events',
    ];

    // The Puzzlers card's second link: Compare lives under Puzzlers (D8) - for the viewers its registry entry is for
    private const string COMPARE_ENTRY = 'compare';

    public function __construct(
        readonly private NavigationRegistry $navigationRegistry,
        readonly private NavigationViewerFactory $navigationViewerFactory,
        readonly private CommunityPreview $communityPreview,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/komunita',
            'en' => '/en/community',
            'es' => '/es/comunidad',
            'ja' => '/ja/コミュニティ',
            'fr' => '/fr/communaute',
            'de' => '/de/community',
        ],
        name: 'community',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $sections = [];

        foreach (self::SECTIONS as $item => $entryId) {
            $entry = $this->navigationRegistry->byId($entryId);
            assert($entry instanceof NavEntry);

            $sections[$item] = $entry;
        }

        $compare = $this->navigationRegistry->byId(self::COMPARE_ENTRY);
        assert($compare instanceof NavEntry);

        // The chrome's viewer (memoised per request, built from the profile row - no statement)
        $viewer = $this->navigationViewerFactory->current();

        return $this->render('community/index.html.twig', [
            'sections' => $sections,
            'compare' => $viewer->sees($compare->visibility) ? $compare : null,
            'landing' => $this->communityPreview->build($request->getLocale()),
        ]);
    }
}
