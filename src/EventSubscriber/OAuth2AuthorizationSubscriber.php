<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Auth0\Symfony\Models\Stateful\User as Auth0User;
use Auth0\Symfony\Models\User as Auth0TestUser;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2UserConsent;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\OAuth2UserConsentRepository;
use SpeedPuzzling\Web\Security\OAuth2User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

final readonly class OAuth2AuthorizationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private OAuth2UserConsentRepository $consentRepository,
        private EntityManagerInterface $entityManager,
        private Environment $twig,
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE => ['onAuthorizationRequest', 10],
        ];
    }

    public function onAuthorizationRequest(AuthorizationRequestResolveEvent $event): void
    {
        // User is guaranteed to be authenticated by the AuthorizationController's #[IsGranted] attribute.
        // We get the user directly from the token storage to support both production Auth0 users
        // and test users created via loginUser().
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        // Get player from the user (works with Auth0User, test User, and OAuth2User)
        $player = $user !== null ? $this->getPlayerFromUser($user) : null;

        if ($player === null) {
            // This should not happen in production (controller requires auth).
            // Deny the request if we can't identify the player.
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);
            return;
        }

        $event->setUser(new OAuth2User($player));

        $clientIdentifier = $event->getClient()->getIdentifier();
        $requestedScopes = array_map(
            static fn(Scope $scope) => (string) $scope,
            $event->getScopes(),
        );

        $existingConsent = $this->consentRepository->findByPlayerAndClient(
            $player->id->toString(),
            $clientIdentifier,
        );

        if ($existingConsent !== null && $this->scopesCovered($existingConsent->scopes, $requestedScopes)) {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null && $request->isMethod('POST') && $request->request->get('consent') === 'approve') {
            if ($existingConsent !== null) {
                $existingConsent->updateScopes(array_unique([...$existingConsent->scopes, ...$requestedScopes]));
            } else {
                $consent = new OAuth2UserConsent(
                    id: Uuid::uuid7(),
                    player: $player,
                    clientIdentifier: $clientIdentifier,
                    scopes: $requestedScopes,
                    consentedAt: $this->clock->now(),
                );
                $this->entityManager->persist($consent);
            }

            $this->entityManager->flush();
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);
            return;
        }

        if ($request !== null && $request->isMethod('POST') && $request->request->get('consent') === 'deny') {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);
            return;
        }

        $response = new Response(
            $this->twig->render('oauth2/consent.html.twig', [
                'client' => $event->getClient(),
                'scopes' => $requestedScopes,
                'player' => $player,
            ]),
        );

        $event->setResponse($response);
    }

    private function getPlayerFromUser(object $user): null|Player
    {
        // Production Auth0 user (stateful)
        if ($user instanceof Auth0User) {
            $userId = $user->getUserIdentifier();
            return $this->entityManager->getRepository(Player::class)->findOneBy(['userId' => $userId]);
        }

        // Test Auth0 user (from loginUser() in tests)
        if ($user instanceof Auth0TestUser) {
            $userId = $user->getUserIdentifier();
            return $this->entityManager->getRepository(Player::class)->findOneBy(['userId' => $userId]);
        }

        if ($user instanceof OAuth2User) {
            return $user->player;
        }

        return null;
    }

    /**
     * @param array<string> $consentedScopes
     * @param array<string> $requestedScopes
     */
    private function scopesCovered(array $consentedScopes, array $requestedScopes): bool
    {
        foreach ($requestedScopes as $scope) {
            if (!in_array($scope, $consentedScopes, true)) {
                return false;
            }
        }

        return true;
    }
}
