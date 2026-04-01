<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OAuth2;

use Auth0\Symfony\Models\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Repository\OAuth2ClientRequestRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ClaimOAuth2CredentialsController extends AbstractController
{
    public function __construct(
        private readonly OAuth2ClientRequestRepository $requestRepository,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prevzit-oauth2-pristupove-udaje/{claimToken}',
            'en' => '/en/claim-oauth2-credentials/{claimToken}',
            'es' => '/es/reclamar-credenciales-oauth2/{claimToken}',
            'ja' => '/ja/OAuth2認証情報取得/{claimToken}',
            'fr' => '/fr/reclamer-identifiants-oauth2/{claimToken}',
            'de' => '/de/oauth2-zugangsdaten-abholen/{claimToken}',
        ],
        name: 'claim_oauth2_credentials',
    )]
    public function __invoke(#[CurrentUser] User $user, string $claimToken): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $tokenHash = hash('sha256', $claimToken);
        $request = $this->requestRepository->findByClaimTokenHash($tokenHash);

        if ($request === null) {
            $this->addFlash('danger', 'Invalid or expired claim link.');

            return $this->redirectToRoute('edit_profile');
        }

        if ($request->player->id->toString() !== $player->playerId) {
            $this->addFlash('danger', 'This claim link belongs to a different user.');

            return $this->redirectToRoute('edit_profile');
        }

        if ($request->credentialClaimExpiresAt !== null && $request->credentialClaimExpiresAt < new DateTimeImmutable()) {
            $this->addFlash('danger', 'This claim link has expired.');

            return $this->redirectToRoute('edit_profile');
        }

        if ($request->credentialsClaimed) {
            $this->addFlash('danger', 'Credentials have already been claimed.');

            return $this->redirectToRoute('edit_profile');
        }

        $clientIdentifier = $request->clientIdentifier;
        $clientSecret = $request->clientSecret;

        $request->markCredentialsClaimed();
        $this->entityManager->flush();

        return $this->render('oauth2/claim-credentials.html.twig', [
            'client_name' => $request->clientName,
            'client_identifier' => $clientIdentifier,
            'client_secret' => $clientSecret,
            'application_type' => $request->applicationType->value,
        ]);
    }
}
