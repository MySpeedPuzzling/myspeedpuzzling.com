<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetOrganizations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapStaticController extends AbstractController
{
    use SitemapResponseTrait;

    /**
     * @var list<string>
     */
    /**
     * The progression pages (docs/features/xp-levels/launch-2026-10.md) - listed once the xp-system gate opens for
     * guests (launch ready), never before: until then they answer 404 to anybody but admins
     */
    private const array PROGRESSION_ROUTES = [
        'progression_hub',
        'xp_explainer',
        'xp_fair_play',
        'badges_overview',
        // Each progression board is its own page; the Achievement Points board asks guests to sign in, so it is left out
        'level_leaderboard',
        'karma_leaderboard',
    ];

    private const array STATIC_ROUTES = [
        'homepage',
        'faq',
        'for_developers',
        'for_organizers',
        'ladder',
        'ladder_solo_500_pieces',
        'ladder_solo_1000_pieces',
        'ladder_pairs_500_pieces',
        'ladder_pairs_1000_pieces',
        'ladder_groups_500_pieces',
        'ladder_groups_1000_pieces',
        'privacy_policy',
        'data_deletion',
        'puzzles',
        'players',
        'players_directory',
        'recent_activity',
        'terms_of_service',
        // 'hub' is deliberately missing: it is `noindex, follow` (a dashboard, not a landing page)
        'events',
        // The partner finder's gate (docs/features/partner-finder.md "Part D as built") - the per-event finders are noindex
        'partner_finder',
        'marketplace',
        'puzzle_tracker_app',
        'feature_requests',
        'methodology',
        'msp_rating_ladder',
        'wjpc_hub',
        'puzzle_picker',
        'getting_started',
    ];

    public function __construct(
        private readonly GetOrganizations $getOrganizations,
        private readonly XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(path: '/sitemap-static.xml', name: 'sitemap_static')]
    public function __invoke(): Response
    {
        $entries = [];

        foreach (self::STATIC_ROUTES as $route) {
            array_push($entries, ...$this->localizedEntries($route));
        }

        if ($this->xpFeatureGate->isVisibleFor(null)) {
            foreach (self::PROGRESSION_ROUTES as $route) {
                array_push($entries, ...$this->localizedEntries($route));
            }
        }

        // The organizations directory (docs/features/organizations/README.md "Directory") - once it lists anything
        if ($this->getOrganizations->anyPublic()) {
            array_push($entries, ...$this->localizedEntries('organizations'));
        }

        return $this->urlsetResponse($entries);
    }
}
