<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\RevokeOAuth2Consent;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RevokeOAuth2ConsentController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odvolat-pristup-aplikace/{consentId}',
            'en' => '/en/revoke-oauth2-consent/{consentId}',
            'es' => '/es/revocar-consentimiento-oauth2/{consentId}',
            'ja' => '/ja/OAuth2同意取り消し/{consentId}',
            'fr' => '/fr/revoquer-consentement-oauth2/{consentId}',
            'de' => '/de/oauth2-zustimmung-widerrufen/{consentId}',
        ],
        name: 'revoke_oauth2_consent',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] User $user, string $consentId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $this->messageBus->dispatch(
            new RevokeOAuth2Consent(
                playerId: $player->playerId,
                consentId: $consentId,
            ),
        );

        $this->addFlash('success', $this->translator->trans('flashes.oauth2_consent_revoked'));

        return $this->redirectToRoute('edit_profile');
    }
}
