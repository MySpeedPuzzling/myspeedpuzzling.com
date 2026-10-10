<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerKarma;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "What did I get my karma for?" (docs/features/karma.md) - the owner's own karma, item by item, newest first. Read
 * straight from the derivation, so it always explains the stored total; copies merged into an earlier puzzle are
 * listed as not counted.
 */
final class KarmaHistoryController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerKarma $getPlayerKarma,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/moje/karma',
            'en' => '/en/my/karma',
            'es' => '/es/mi/karma',
            'ja' => '/ja/マイ/karma',
            'fr' => '/fr/mon/karma',
            'de' => '/de/meine/karma',
        ],
        name: 'karma_history',
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        // Karma launches with XP - the one launch switch
        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $breakdown = $this->getPlayerKarma->breakdown($profile->playerId);
        $lastPage = max(1, (int) ceil($breakdown->items() / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $lastPage);

        return $this->render('karma/history.html.twig', [
            'player' => $profile,
            'breakdown' => $breakdown,
            'items' => $breakdown->items() > 0
                ? $this->getPlayerKarma->items($profile->playerId, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
                : [],
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }
}
