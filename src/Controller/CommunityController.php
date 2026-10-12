<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Navigation\NavEntry;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Community landing (docs/features/navigation README §4.9, COM-01a): the place's start page with its three
 * sections - Leaderboards, Puzzlers, Events. Each card's title and icon come from the section's registry entry, so a
 * destination has one name everywhere; COM-01 adds the previews.
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

    public function __construct(
        readonly private NavigationRegistry $navigationRegistry,
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
    public function __invoke(): Response
    {
        $sections = [];

        foreach (self::SECTIONS as $item => $entryId) {
            $entry = $this->navigationRegistry->byId($entryId);
            assert($entry instanceof NavEntry);

            $sections[$item] = $entry;
        }

        return $this->render('community/index.html.twig', [
            'sections' => $sections,
        ]);
    }
}
