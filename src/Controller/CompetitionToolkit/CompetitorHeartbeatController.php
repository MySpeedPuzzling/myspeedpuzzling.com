<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitorHeartbeats;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitorHeartbeatToken;
use SpeedPuzzling\Web\Value\CompetitorHeartbeat;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The heartbeat of a competitor's own page while their round runs (reliability hardening T2,
 * docs/features/competitions-management/competitor-pages.md "Timing you can trust"):
 * `POST /-/competitor-heartbeat {token, atMs, offsetMs, rttMs, sampleAgeMs, unsent, oldestUnsentTapMs}` → the beat goes
 * to CompetitorHeartbeats (redis-state, one Lua call) as "last heard from <entry, player> at <now>" → `{serverNowMs}`,
 * a clock sample for the phone. A later proposal of that entry is judged against it (TimeProposalTiming).
 *
 * As cheap as an answer gets - 300 phones every 15 s: the `stateless` firewall (no session, no user), the token from
 * the competitor state JSON is the only access right (CompetitorHeartbeatToken - no database), no ORM, no Postgres
 * (pinned by CompetitorHeartbeatControllerTest's statement count). Limited per player and round
 * (`competitor_heartbeat`, 30 a minute - one atomic Redis call). A store that is down never fails a heartbeat: the clock
 * sample still goes out (and the proposal is then judged without evidence - the tap is trusted).
 *
 * Answers: 200 `{serverNowMs}`; 400 `invalid_json`; 403 `invalid_token` / `expired_token` (the page asks for the state,
 * which carries a fresh token); 422 `invalid_fields`; 429 `rate_limited`. Every answer carries `serverNowMs`,
 * `private, no-store`.
 */
final class CompetitorHeartbeatController extends AbstractController
{
    public const int MAX_BODY_BYTES = 4096;

    public function __construct(
        private readonly CompetitorHeartbeatToken $token,
        private readonly CompetitorHeartbeats $heartbeats,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'online_rate_limiter.competitor_heartbeat')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route(path: '/-/competitor-heartbeat', name: 'competitor_heartbeat', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $nowMs = RoundClock::milliseconds($this->clock->now());
        $content = $request->getContent();

        if (strlen($content) > self::MAX_BODY_BYTES) {
            return $this->answer($nowMs, ['error' => 'invalid_json'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $body = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->answer($nowMs, ['error' => 'invalid_json'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return $this->answer($nowMs, ['error' => 'invalid_json'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $token = $body['token'] ?? null;
        $claims = is_string($token) ? $this->token->verify($token, $nowMs) : null;

        if ($claims === null) {
            return $this->answer($nowMs, ['error' => 'invalid_token'], JsonResponse::HTTP_FORBIDDEN);
        }

        if ($claims->expired) {
            return $this->answer($nowMs, ['error' => 'expired_token'], JsonResponse::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $body */
        $heartbeat = CompetitorHeartbeat::read($body);

        if ($heartbeat === null) {
            return $this->answer($nowMs, ['error' => 'invalid_fields'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limit = $this->limiter->create($claims->playerId . '-' . $claims->roundId)->consume();

        if ($limit->isAccepted() === false) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - intdiv($nowMs, 1000));

            return $this->answer($nowMs, ['error' => 'rate_limited'], JsonResponse::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfter]);
        }

        try {
            $this->heartbeats->beat($claims->roundId, $claims->entry, $claims->playerId, $heartbeat, $nowMs);
        } catch (\Throwable $e) {
            $this->logger->warning('Recording a competitor heartbeat failed', [
                'round_id' => $claims->roundId,
                'exception' => $e,
            ]);
        }

        return $this->answer($nowMs, []);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function answer(int $nowMs, array $data, int $status = JsonResponse::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse(['serverNowMs' => $nowMs, ...$data], $status, $headers);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
