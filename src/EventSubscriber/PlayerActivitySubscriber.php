<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RecordPlayerActivity;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records "this player was active today" - the raw fact behind DAU/retention
 * analytics (docs/features/activity-analytics.md). Runs at kernel.terminate:
 * the response is already flushed, so it adds zero request latency and cannot
 * interfere with response cache headers.
 *
 * Cost per request is one cache read: a Redis marker per user per UTC day
 * short-circuits every request after the first, and the DB upsert underneath
 * is ON CONFLICT DO NOTHING - correct even when the marker is gone (Redis
 * restart, TTL edge). Everything is wrapped: activity tracking must never
 * break or slow a request.
 */
final readonly class PlayerActivitySubscriber implements EventSubscriberInterface
{
    private const string MAIN_FIREWALL = 'main';
    private const int MARKER_TTL_SECONDS = 26 * 3600;

    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private CacheItemPoolInterface $playerActivityCache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        try {
            $user = $this->security->getUser();

            if ($user === null) {
                return;
            }

            // Web sessions only: the api/internal_api firewalls are a different
            // population (PAT/OAuth2 clients track last_used_at already)
            $firewall = $this->security->getFirewallConfig($event->getRequest());

            if ($firewall === null || $firewall->getName() !== self::MAIN_FIREWALL) {
                return;
            }

            $day = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Ymd');
            $marker = $this->playerActivityCache->getItem(sha1($user->getUserIdentifier()) . '-' . $day);

            if ($marker->isHit()) {
                return;
            }

            $this->messageBus->dispatch(new RecordPlayerActivity($user->getUserIdentifier()));

            // Only after a successful dispatch - a failed write retries on the next request
            $marker->set(true);
            $marker->expiresAfter(self::MARKER_TTL_SECONDS);
            $this->playerActivityCache->save($marker);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record player activity', [
                'exception' => $e,
            ]);
        }
    }
}
