<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use SpeedPuzzling\Web\Repository\OAuth2UserConsentRepository;
use SpeedPuzzling\Web\Security\OAuth2User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiTokenUsageSubscriber implements EventSubscriberInterface
{
    private const int THROTTLE_SECONDS = 300;

    public function __construct(
        private readonly Security $security,
        private readonly OAuth2UserConsentRepository $oAuth2UserConsentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            return;
        }

        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/v1/') === false) {
            return;
        }

        $user = $this->security->getUser();

        if ($user instanceof OAuth2User === false) {
            return;
        }

        $token = $this->security->getToken();

        if ($token instanceof OAuth2Token === false) {
            return;
        }

        $clientIdentifier = $token->getOAuthClientId();

        $consent = $this->oAuth2UserConsentRepository->findByPlayerAndClient(
            $user->getPlayer()->id->toString(),
            $clientIdentifier,
        );

        if ($consent === null) {
            return;
        }

        $now = new DateTimeImmutable();

        if ($consent->lastUsedAt !== null) {
            $diff = $now->getTimestamp() - $consent->lastUsedAt->getTimestamp();

            if ($diff < self::THROTTLE_SECONDS) {
                return;
            }
        }

        $consent->updateLastUsedAt();
        $this->entityManager->flush();
    }
}
