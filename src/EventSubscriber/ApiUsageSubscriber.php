<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Security\OAuth2User;
use SpeedPuzzling\Web\Security\PatUser;
use SpeedPuzzling\Web\Services\ApiUsage\ApiUsageCounter;
use SpeedPuzzling\Web\Services\ApiUsage\ApiUsageOperation;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiRequestRecord;
use SpeedPuzzling\Web\Value\ApiStatusClass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Counts every authenticated public API request (docs/features/api/usage-statistics.md).
 *
 * The counting happens on kernel.terminate. In FrankenPHP worker mode the runner
 * calls terminate() only after frankenphp_handle_request() returned, i.e. after the
 * response went out - the client never waits for it. Rules that follow from the
 * worker mode:
 *  - nothing may escape onTerminate(): an exception there ends the worker loop and
 *    FrankenPHP has to boot a fresh worker,
 *  - one Redis round-trip and nothing else - the thread is busy until it returns,
 *  - no state in this object - everything comes from the event's request/response;
 *    the security token is still set at terminate (services reset when the next
 *    request starts),
 *  - the duration is measured here (hrtime) - $_SERVER['REQUEST_TIME_FLOAT'] is
 *    assembled per request by the runner and not something to rely on.
 */
final readonly class ApiUsageSubscriber implements EventSubscriberInterface
{
    private const string PATH_PREFIX = '/api/v1/';
    private const string STARTED_AT_ATTRIBUTE = '_api_usage_started_at';
    private const string DURATION_ATTRIBUTE = '_api_usage_duration_ms';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ApiUsageCounter $apiUsageCounter,
        private ApiUsageOperation $apiUsageOperation,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the router (32) and the firewall (8): the whole request is measured
            KernelEvents::REQUEST => ['onRequest', 4096],
            // After every other response listener
            KernelEvents::RESPONSE => ['onResponse', -4096],
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && self::isApiRequest($event->getRequest())) {
            $event->getRequest()->attributes->set(self::STARTED_AT_ATTRIBUTE, hrtime(true));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && self::isApiRequest($event->getRequest())) {
            $event->getRequest()->attributes->set(self::DURATION_ATTRIBUTE, self::elapsedMs($event->getRequest()));
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        try {
            $request = $event->getRequest();

            if (!self::isApiRequest($request)) {
                return;
            }

            $caller = $this->caller();

            if ($caller === null) {
                return;
            }

            $duration = $request->attributes->get(self::DURATION_ATTRIBUTE);

            $this->apiUsageCounter->record(new ApiRequestRecord(
                caller: $caller,
                operation: $this->apiUsageOperation->forRequest($request),
                statusClass: ApiStatusClass::fromStatusCode($event->getResponse()->getStatusCode()),
                durationMs: is_int($duration) ? $duration : self::elapsedMs($request),
                at: $this->clock->now(),
            ));
        } catch (\Throwable $e) {
            // Usage statistics must never break an API request (nor, in worker mode, the worker)
            $this->logger->warning('Recording API usage failed', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Unauthenticated requests (scanners, invalid tokens) have no caller and are not counted.
     */
    private function caller(): null|ApiCaller
    {
        $token = $this->tokenStorage->getToken();

        if ($token === null) {
            return null;
        }

        $user = $token->getUser();

        if ($user instanceof PatUser) {
            return ApiCaller::personalAccessToken($user->personalAccessTokenId, $user->getPlayer()->id->toString());
        }

        if (!$token instanceof OAuth2Token) {
            return null;
        }

        $clientIdentifier = $token->getOAuthClientId();

        if ($user instanceof OAuth2User) {
            return ApiCaller::oauth2User($clientIdentifier, $user->getPlayer()->id->toString());
        }

        // client_credentials: the bundle's ClientCredentialsUser, no player behind the token
        return ApiCaller::oauth2Client($clientIdentifier);
    }

    private static function isApiRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), self::PATH_PREFIX);
    }

    private static function elapsedMs(Request $request): int
    {
        $startedAt = $request->attributes->get(self::STARTED_AT_ATTRIBUTE);

        if (!is_int($startedAt)) {
            return 0;
        }

        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
