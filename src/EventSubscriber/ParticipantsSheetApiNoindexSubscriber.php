<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every answer of the participants spreadsheet's JSON endpoints (routes `participants_sheet_*` - state, version, changes,
 * registration, player search; docs/features/competitions-management/participants-spreadsheet.md) says
 * `X-Robots-Tag: noindex, nofollow`, whatever answered it: the controller, OfficialResultsApi's 401/403, the JSON 404 of
 * OfficialResultsApiNotFoundSubscriber. The page itself (`participants_sheet`) sets it in its controller.
 */
final readonly class ParticipantsSheetApiNoindexSubscriber implements EventSubscriberInterface
{
    private const string ROUTE_PREFIX = 'participants_sheet_';

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');

        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
}
