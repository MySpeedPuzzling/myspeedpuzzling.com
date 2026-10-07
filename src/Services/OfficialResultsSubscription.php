<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubRegistry;

/**
 * The live updates subscription of one organiser page (docs/features/competitions-management/official-results.md
 * "Live updates"): a short-lived subscriber JWT for exactly the topics that page follows, which the page sends as
 * `Authorization: Bearer` on its own stream (assets/official_results_events.js) - never in a URL, never in the
 * `mercureAuthorization` cookie. The cookie is rewritten by every signed-in response with that request's topics only,
 * so a round topic kept there was lost on the next reconnect; a token passed per connection beats the cookie.
 *
 * Issue it only after the voter allowed the page (organiser, or a referee for the live entry's round state). Topics
 * are always listed one by one - never a URI template, which would grant every round. Signed with the hub's key
 * (MercureBundle's token factory, like the cookie), subscribe only, an hour long: the pages fetch a new one with their
 * state well before it ends (the hub closes a stream ~7 s before `exp` and answers 401 after it).
 *
 * Null (logged) when no token can be made - the page then lives on its periodic state fetch.
 */
final readonly class OfficialResultsSubscription
{
    public const int LIFETIME_SECONDS = 3600;

    public function __construct(
        private HubRegistry $hubRegistry,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public static function stopwatchTopic(string $roundId): string
    {
        return '/round-stopwatch/' . strtolower($roundId);
    }

    /**
     * One round's private results topic and its public stopwatch topic - the live entry, the results desk, seating.
     *
     * @return null|array{url: string, topics: list<string>, token: string, expiresAt: string, expiresIn: int}
     */
    public function forRound(string $roundId): null|array
    {
        return $this->forTopics([OfficialResultsLiveUpdates::topic($roundId), self::stopwatchTopic($roundId)]);
    }

    /**
     * Every listed round's private results topic - the results overview.
     *
     * @param list<string> $roundIds
     * @return null|array{url: string, topics: list<string>, token: string, expiresAt: string, expiresIn: int}
     */
    public function forRounds(array $roundIds): null|array
    {
        return $this->forTopics(array_values(array_unique(array_map(OfficialResultsLiveUpdates::topic(...), $roundIds))));
    }

    /**
     * @param list<string> $topics
     * @return null|array{url: string, topics: list<string>, token: string, expiresAt: string, expiresIn: int}
     */
    private function forTopics(array $topics): null|array
    {
        if ($topics === []) {
            return null;
        }

        try {
            $hub = $this->hubRegistry->getHub();
            $factory = $hub->getFactory();

            if ($factory === null) {
                $this->logger->warning('The Mercure hub has no token factory - official results pages follow no live updates');

                return null;
            }

            // Whole seconds: `exp` is a plain NumericDate
            $expiresAt = new \DateTimeImmutable('@' . ($this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS));

            return [
                'url' => $hub->getPublicUrl(),
                'topics' => $topics,
                'token' => $factory->create(subscribe: $topics, publish: [], additionalClaims: ['exp' => $expiresAt]),
                'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
                // The page counts from when it got the token - a device's clock may be off, the lifetime is not
                'expiresIn' => self::LIFETIME_SECONDS,
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not create an official results subscriber token', [
                'topics' => $topics,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
