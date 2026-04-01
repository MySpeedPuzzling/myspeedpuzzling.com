<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\AcceptFairUsePolicy;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AcceptFairUsePolicyController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prijmout-zasady-fer-pouzivani',
            'en' => '/en/accept-fair-use-policy',
            'es' => '/es/aceptar-politica-de-uso-justo',
            'ja' => '/ja/フェアユースポリシー承認',
            'fr' => '/fr/accepter-politique-utilisation-equitable',
            'de' => '/de/fair-use-richtlinie-akzeptieren',
        ],
        name: 'accept_fair_use_policy',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $this->messageBus->dispatch(
            new AcceptFairUsePolicy(playerId: $player->playerId),
        );

        $this->addFlash('success', $this->translator->trans('flashes.fair_use_policy_accepted'));

        return $this->redirectToRoute('edit_profile');
    }
}
