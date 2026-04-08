<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ReferralCookieSubscriber implements EventSubscriberInterface
{
    public const string COOKIE_NAME = 'referral_ref';
    private const int COOKIE_LIFETIME_DAYS = 30;

    public function __construct(
        private PlayerRepository $playerRepository,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
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

        // Add flash message
        $playerName = $player->name ?? ('#' . $player->code);

        try {
            $session = $this->requestStack->getSession();

            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add(
                    'success',
                    $this->translator->trans('referral.flash.cookie_set', ['%name%' => $playerName]),
                );
            }
        } catch (\Symfony\Component\HttpFoundation\Exception\SessionNotFoundException) {
            // No session available (e.g. in tests)
        }

        // Redirect to the same URL without ?ref= so the URL is clean
        $query = $request->query->all();
        unset($query['ref']);
        $cleanUrl = $request->getPathInfo();
        if ($query !== []) {
            $cleanUrl .= '?' . http_build_query($query);
        }

        $response = new RedirectResponse($cleanUrl);
        $response->headers->setCookie(
            Cookie::create(self::COOKIE_NAME)
                ->withValue($player->code)
                ->withExpires(new \DateTimeImmutable('+' . self::COOKIE_LIFETIME_DAYS . ' days'))
                ->withPath('/')
                ->withHttpOnly(true)
                ->withSameSite('lax'),
        );

        $event->setResponse($response);
    }
}
