<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Security\InternalApiAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * One log line per write through the internal API - who (the player the API acts as), what (method, path, route),
 * on which ids (the route's ids, plus the id of anything created) and how it ended (status). Refusals are logged too;
 * a request without a valid token is not (it wrote nothing and is mostly noise from bots).
 *
 * Its own channel `internal_api_audit`, at info level: kept out of Sentry (warning and above become issues there), and
 * in production written to stderr (→ Loki) on its own, not through the `fingers_crossed` handler that drops info
 * records of a request without a warning (config/packages/prod/monolog.php).
 */
final readonly class InternalApiAuditSubscriber implements EventSubscriberInterface
{
    /** Set by a controller that creates something, so the log names it */
    public const string CREATED_ID_ATTRIBUTE = '_internal_api_created_id';

    public function __construct(
        #[Autowire(service: 'monolog.logger.internal_api_audit')]
        private LoggerInterface $logger,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private string $actingPlayerId,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->isMainRequest() === false || $request->isMethodSafe()) {
            return;
        }

        if (InternalApiAuthenticator::isInternalApiRequest($request) === false) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();

        if ($status === Response::HTTP_UNAUTHORIZED) {
            return;
        }

        /** @var array<string, mixed> $routeParameters */
        $routeParameters = $request->attributes->get('_route_params', []);
        $createdId = $request->attributes->get(self::CREATED_ID_ATTRIBUTE);

        $this->logger->info('Internal API write: {method} {path} answered {status}', [
            'method' => $request->getMethod(),
            'path' => rawurldecode($request->getPathInfo()),
            'route' => $request->attributes->get('_route'),
            'status' => $status,
            'actingPlayerId' => $this->actingPlayerId !== '' ? $this->actingPlayerId : null,
            'targetIds' => array_filter($routeParameters, is_string(...)),
            'createdId' => is_string($createdId) ? $createdId : null,
        ]);
    }
}
