<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetXpHistory;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * XP audit page — every ledger entry with its reason and link. User-facing
 * auditability is a locked requirement of the XP system.
 */
final class XpHistoryController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        readonly private GetXpHistory $getXpHistory,
        readonly private GetXpProfile $getXpProfile,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/moje/xp-historie',
            'en' => '/en/my/xp-history',
            'es' => '/es/mi/xp-historial',
            'ja' => '/ja/マイ/xp履歴',
            'fr' => '/fr/mon/xp-historique',
            'de' => '/de/meine/xp-verlauf',
        ],
        name: 'xp_history',
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->getXpHistory->countForPlayer($profile->playerId);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);

        return $this->render('xp_history.html.twig', [
            'entries' => $this->getXpHistory->forPlayer($profile->playerId, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'xp_profile' => $this->getXpProfile->byPlayerId($profile->playerId),
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }
}
