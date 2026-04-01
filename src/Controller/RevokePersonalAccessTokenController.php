<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\RevokePersonalAccessToken;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RevokePersonalAccessTokenController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odvolat-pristupovy-token/{tokenId}',
            'en' => '/en/revoke-personal-access-token/{tokenId}',
            'es' => '/es/revocar-token-de-acceso-personal/{tokenId}',
            'ja' => '/ja/パーソナルアクセストークン取り消し/{tokenId}',
            'fr' => '/fr/revoquer-jeton-acces-personnel/{tokenId}',
            'de' => '/de/persoenlichen-zugangstoken-widerrufen/{tokenId}',
        ],
        name: 'revoke_personal_access_token',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] User $user, string $tokenId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $this->messageBus->dispatch(
            new RevokePersonalAccessToken(
                playerId: $player->playerId,
                tokenId: $tokenId,
            ),
        );

        $this->addFlash('success', $this->translator->trans('flashes.pat_revoked'));

        return $this->redirectToRoute('edit_profile');
    }
}
