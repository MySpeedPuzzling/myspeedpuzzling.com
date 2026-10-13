<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Value\OnlineAccessContext;
use SpeedPuzzling\Web\Value\OnlineToken;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The shared rules of the participant API of online competitions (`/api/compete/{token}/…`,
 * docs/features/online-competitions/contracts/phase-1.md §6) - the personal link's token in the path is the only
 * access right: no session, no user, no cookie (the `compete_api` firewall has security off). Every link is treated
 * as a possibly hostile client.
 * - Every answer is JSON, `private, no-store`, and carries `serverNowMs` - each one is a free clock sample for the
 *   phone (README §5.2), refusals too: `{"error": "<code>", "serverNowMs": …}`.
 * - admit(): the client IP's limiter first (`rate_limited` 429 + Retry-After - links guessed at), then the token's
 *   shape (a token that is no token never reaches the cache or the database: `unknown_link` 404), then the link's
 *   limiter; event batches and presigned uploads have a limiter of their own each (admitEvents() / admitMedia()).
 * - access(): OnlineAccess, with a link found unknown answered from the cache for a few seconds
 *   (OnlineAccessLight::isUnknown()) - unknown or removed: `unknown_link`.
 * - Bodies are JSON objects (`invalid_json` 400), at most MAX_BODY_BYTES (`request_too_large` 413). No content type
 *   is required - no cookie authority is involved, so there is nothing to forge.
 */
final readonly class OnlineParticipantApi
{
    // 50 events of at most 2 KB of data each (plus a burst's manifest) fit, with room to spare
    public const int MAX_BODY_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private OnlineAccess $onlineAccess,
        private OnlineAccessLight $onlineAccessLight,
        private RateLimiterFactoryInterface $onlineParticipantIpLimiter,
        private RateLimiterFactoryInterface $onlineParticipantLimiter,
        private RateLimiterFactoryInterface $onlineParticipantEventsLimiter,
        private RateLimiterFactoryInterface $onlineParticipantMediaLimiter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The checks before anything is read: the client IP's rate limit, the token's shape, the link's rate limit.
     * Null = go on.
     */
    public function admit(Request $request, string $token): null|JsonResponse
    {
        $refusal = $this->limit($this->onlineParticipantIpLimiter, $request->getClientIp() ?? 'unknown');

        if ($refusal !== null) {
            return $refusal;
        }

        if (OnlineToken::looksValid($token) === false) {
            return $this->error('unknown_link', JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->limit($this->onlineParticipantLimiter, $token);
    }

    /**
     * The link's limit of event batches - after admit()
     */
    public function admitEvents(string $token): null|JsonResponse
    {
        return $this->limit($this->onlineParticipantEventsLimiter, $token);
    }

    /**
     * The link's limit of presigned uploads - after admit()
     */
    public function admitMedia(string $token): null|JsonResponse
    {
        return $this->limit($this->onlineParticipantMediaLimiter, $token);
    }

    /**
     * What the link opens (OnlineAccess), or the 404 to answer with
     */
    public function access(string $token): OnlineAccessContext|JsonResponse
    {
        if ($this->onlineAccessLight->isUnknown($token)) {
            return $this->error('unknown_link', JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            return $this->onlineAccess->forToken($token);
        } catch (OnlineLinkNotFound) {
            $this->onlineAccessLight->rememberUnknown($token);

            return $this->error('unknown_link', JsonResponse::HTTP_NOT_FOUND);
        }
    }

    /**
     * The request's JSON object, or the refusal to answer with
     *
     * @return array<string, mixed>|JsonResponse
     */
    public function body(Request $request): array|JsonResponse
    {
        $content = $request->getContent();

        if (strlen($content) > self::MAX_BODY_BYTES) {
            return $this->error('request_too_large', JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $body = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('invalid_json', JsonResponse::HTTP_BAD_REQUEST);
        }

        // `{}` decodes to an empty array; a non-empty list is no object
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return $this->error('invalid_json', JsonResponse::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * An answer - with `serverNowMs` first, unless the data carries its own (the state takes it when the rows are read)
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function json(array $data, int $status = JsonResponse::HTTP_OK, array $headers = []): JsonResponse
    {
        if (array_key_exists('serverNowMs', $data) === false) {
            $data = ['serverNowMs' => $this->nowMs(), ...$data];
        }

        $response = new JsonResponse($data, $status, $headers);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    public function error(string $error, int $status, array $headers = []): JsonResponse
    {
        return $this->json(['error' => $error, 'serverNowMs' => $this->nowMs()], $status, $headers);
    }

    public function nowMs(): int
    {
        return RoundClock::milliseconds($this->clock->now());
    }

    private function limit(RateLimiterFactoryInterface $limiter, string $key): null|JsonResponse
    {
        $limit = $limiter->create($key)->consume();

        if ($limit->isAccepted()) {
            return null;
        }

        // The limiter counts on the system clock
        $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        return $this->error('rate_limited', JsonResponse::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
