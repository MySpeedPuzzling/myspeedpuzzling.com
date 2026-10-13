<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitTokens;
use SpeedPuzzling\Web\Services\LiveKit\StudioSpectators;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineWatchGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A spectator's access token to a round's studio (docs/features/online-competitions/contracts/phase-1b.md §3, §5
 * `online_watch_token`) - what the public watch page (OnlineWatchController) connects with: `{url, token, identity,
 * room}` of LiveKitTokens::spectator() (`s:{random}`: watching only, hidden from everybody in the room).
 *
 * Anybody may ask: no session state, no CSRF token, no body - the page carries nothing but the round. In this order:
 * - the client IP's limit (`online_rate_limiter.online_watch_ip`: one atomic call on the cache Redis, like the
 *   participant API's limits - config/packages/rate_limiter.php): `rate_limited` 429 + Retry-After;
 * - the round and its event (OnlineWatchGate): `not_found` 404 for an unknown round and for a draft, pending or rejected
 *   event (its editors excepted), `round_not_online` 404 for a round without online timing;
 * - `video_unavailable` 409 while LIVEKIT_* is empty or the round's video is off;
 * - `studio_full` 409 once the studio holds ONLINE_WATCH_MAX_SPECTATORS spectators (default 200, until Phase 4 brings
 *   HLS) - LiveKit's own count, asked at most every few seconds (StudioSpectators).
 * Every answer is JSON, `private, no-store`.
 */
final class OnlineWatchTokenController extends AbstractController
{
    public const int DEFAULT_MAX_SPECTATORS = 200;

    private readonly int $maxSpectators;

    public function __construct(
        private readonly OnlineWatchGate $onlineWatchGate,
        private readonly StudioSpectators $studioSpectators,
        private readonly LiveKitTokens $liveKitTokens,
        #[Autowire(service: 'online_rate_limiter.online_watch_ip')]
        private readonly RateLimiterFactoryInterface $onlineWatchIpLimiter,
        // Not set = DEFAULT_MAX_SPECTATORS
        #[Autowire(env: 'default::ONLINE_WATCH_MAX_SPECTATORS')]
        null|string $maxSpectators = null,
    ) {
        $this->maxSpectators = self::maxSpectators($maxSpectators);
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/sledovat/token',
            'en' => '/en/online-rounds/{roundId}/watch/token',
            'es' => '/es/online-rounds/{roundId}/watch/token',
            'ja' => '/ja/online-rounds/{roundId}/watch/token',
            'fr' => '/fr/online-rounds/{roundId}/watch/token',
            'de' => '/de/online-rounds/{roundId}/watch/token',
        ],
        name: 'online_watch_token',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $limited = $this->limit($request);

        if ($limited !== null) {
            return $limited;
        }

        try {
            $watch = $this->onlineWatchGate->open($roundId);
        } catch (CompetitionRoundNotFound | DraftNotVisible) {
            return OfficialResultsApi::error('not_found', JsonResponse::HTTP_NOT_FOUND);
        } catch (OnlineRoundNotFound) {
            return OfficialResultsApi::error('round_not_online', JsonResponse::HTTP_NOT_FOUND);
        }

        if ($watch->videoAvailable === false) {
            return OfficialResultsApi::error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        $roundId = $watch->round->id->toString();

        if ($this->studioSpectators->admit($roundId, $this->maxSpectators) === false) {
            return OfficialResultsApi::error('studio_full', JsonResponse::HTTP_CONFLICT);
        }

        return OfficialResultsApi::json($this->liveKitTokens->spectator($roundId)->toArray());
    }

    private static function maxSpectators(null|string $configured): int
    {
        $configured = trim($configured ?? '');

        if ($configured === '' || ctype_digit($configured) === false) {
            return self::DEFAULT_MAX_SPECTATORS;
        }

        return max(1, (int) $configured);
    }

    /**
     * The client IP's limit - null = go on (a cache Redis that does not answer lets the request through, the limiter
     * says so in the log)
     */
    private function limit(Request $request): null|JsonResponse
    {
        $limit = $this->onlineWatchIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();

        if ($limit->isAccepted()) {
            return null;
        }

        // The limiter counts on the system clock
        $response = OfficialResultsApi::error('rate_limited', JsonResponse::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

        return $response;
    }
}
