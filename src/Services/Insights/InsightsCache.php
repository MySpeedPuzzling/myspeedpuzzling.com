<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Query\GetInsightsFingerprint;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\InsightsCacheKey;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The Insights page cache (docs/features/insights/computation.md §4c.8): the pool `insights_cache` on the cache Redis -
 * disposable, a miss only costs a recompute. Every part of a player is keyed by the batch generation
 * (InsightsGeneration::current(), moves on every 15-minute run), a fingerprint of the player's own results,
 * collections and lending (GetInsightsFingerprint), the viewer's blocks and allow list, and the part's own context
 * (filters, membership) - so nothing cached outlives the data it was built from, and an old generation's entries are
 * simply never asked for again (TTL_SECONDS bounds them). Nothing is kept in the service between requests.
 *
 *     $key = $insightsCache->keyFor($playerId);                                   // 1 statement
 *     $result = $insightsCache->get($key, 'rating', ['pieces' => 500], fn () => $ratingOpportunities->build(...));
 *     $closeCalls = $insightsCache->getForGeneration('close_calls', $playerId, [], fn () => ...); // 1 statement
 */
readonly final class InsightsCache
{
    /** Two batch generations and then some: an entry is useless after the next run anyway */
    public const int TTL_SECONDS = 2700;

    public function __construct(
        private CacheInterface $insightsCache,
        private InsightsGeneration $insightsGeneration,
        private GetInsightsFingerprint $getInsightsFingerprint,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * The generation and the fingerprint come from one statement (GetInsightsFingerprint::withGeneration())
     */
    public function keyFor(string $playerId): InsightsCacheKey
    {
        $parts = $this->getInsightsFingerprint->withGeneration($playerId);

        return new InsightsCacheKey(
            playerId: strtolower($playerId),
            generation: $parts['generation'],
            fingerprint: $parts['fingerprint'],
            viewer: $this->viewer(),
        );
    }

    /**
     * @template T
     *
     * @param array<string, null|bool|float|int|string> $context
     * @param callable(): T $compute
     *
     * @return T
     */
    public function get(InsightsCacheKey $key, string $part, array $context, callable $compute): mixed
    {
        return $this->insightsCache->get($key->for($part, $context), static function (ItemInterface $item) use ($compute): mixed {
            $item->expiresAfter(self::TTL_SECONDS);

            return $compute();
        });
    }

    /**
     * A part that depends on the batch only (and on the player's own data only through it) - one statement for the
     * generation, no fingerprint.
     *
     * @template T
     *
     * @param array<string, null|bool|float|int|string> $context
     * @param callable(): T $compute
     *
     * @return T
     */
    public function getForGeneration(string $part, string $playerId, array $context, callable $compute): mixed
    {
        $key = new InsightsCacheKey(strtolower($playerId), $this->insightsGeneration->current(), 'generation', $this->viewer());

        return $this->get($key, $part, $context, $compute);
    }

    private function viewer(): string
    {
        $hidden = $this->hiddenPlayers->ids();
        $revealed = $this->privateProfileAccess->revealedIds();

        if ($hidden === [] && $revealed === []) {
            return 'all';
        }

        sort($hidden);
        sort($revealed);

        return substr(sha1(implode(',', $hidden) . '/' . implode(',', $revealed)), 0, 12);
    }
}
