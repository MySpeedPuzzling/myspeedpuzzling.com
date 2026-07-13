<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\RevealBadge;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RevealBadgeController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odznaky/{badgeId}/odhalit',
            'en' => '/en/badges/{badgeId}/reveal',
            'es' => '/es/insignias/{badgeId}/reveal',
            'ja' => '/ja/バッジ/{badgeId}/reveal',
            'fr' => '/fr/badges/{badgeId}/reveal',
            'de' => '/de/abzeichen/{badgeId}/reveal',
        ],
        name: 'reveal_badge',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(string $badgeId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        if ($this->xpFeatureGate->isVisibleFor($loggedPlayer) === false) {
            throw $this->createNotFoundException();
        }

        $this->messageBus->dispatch(new RevealBadge(
            playerId: $loggedPlayer->playerId,
            badgeId: $badgeId,
        ));

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
