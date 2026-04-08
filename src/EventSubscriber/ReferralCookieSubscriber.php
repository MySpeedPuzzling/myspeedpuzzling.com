<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ReferralCookieSubscriber implements EventSubscriberInterface
{
    public const string COOKIE_NAME = 'referral_ref';
    private const int COOKIE_LIFETIME_DAYS = 30;

    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $refCode = $request->query->get('ref');

        if (!is_string($refCode) || $refCode === '') {
            return;
        }

        // First-touch wins: don't overwrite existing cookie
        if ($request->cookies->has(self::COOKIE_NAME)) {
            return;
        }

        try {
            $player = $this->playerRepository->getByCode($refCode);
        } catch (PlayerNotFound) {
            return;
        }

        if (!$player->isInReferralProgram()) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->setCookie(
            Cookie::create(self::COOKIE_NAME)
                ->withValue($player->code)
                ->withExpires(new \DateTimeImmutable('+' . self::COOKIE_LIFETIME_DAYS . ' days'))
                ->withPath('/')
                ->withHttpOnly(true)
                ->withSameSite('lax'),
        );
    }
}
