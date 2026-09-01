<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "What is this achievement for?" — the explainer behind every medallion on a profile:
 * what it rewards, all five tiers with their requirement and AP, which ones the viewer
 * already holds and how far the next one is.
 *
 * Rendered into the shared `modal-frame`, so a reader never leaves the profile; opening
 * it in its own tab renders the same content as a normal page.
 */
final class AchievementInfoController extends AbstractController
{
    public function __construct(
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uspechy/{type}/info',
            'en' => '/en/achievements/{type}/info',
            'es' => '/es/logros/{type}/info',
            'ja' => '/ja/実績/{type}/info',
            'fr' => '/fr/succes/{type}/info',
            'de' => '/de/erfolge/{type}/info',
        ],
        name: 'achievement_info',
    )]
    public function __invoke(string $type): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $badgeType = BadgeType::tryFrom($type);

        if ($badgeType === null) {
            throw $this->createNotFoundException();
        }

        // Progress belongs to the viewer, never to the profile being looked at — the modal
        // answers "what is this and where am I with it", the holders page answers "who has it".
        $group = $badgeType->isTiered()
            ? $this->getBadgeCatalog->forPlayerAndType($profile?->playerId, $badgeType)
            : null;

        return $this->render('_achievement_info_modal.html.twig', [
            'type' => $badgeType,
            'group' => $group,
            'logged_in' => $profile !== null,
        ]);
    }
}
