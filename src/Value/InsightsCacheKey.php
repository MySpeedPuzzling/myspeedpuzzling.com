<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a cached Insights part of one player is valid for (InsightsCache): the batch generation, the fingerprint of
 * the player's own data, and the viewer's blocks and private-profile allow list (they change who is named).
 */
readonly final class InsightsCacheKey
{
    public function __construct(
        public string $playerId,
        public int $generation,
        public string $fingerprint,
        public string $viewer,
    ) {
    }

    /**
     * @param array<string, null|bool|float|int|string> $context the part's own inputs: filters, membership
     */
    public function for(string $part, array $context = []): string
    {
        ksort($context);

        return 'insights.' . $part . '.' . $this->playerId . '.' . $this->generation . '.' . $this->fingerprint . '.' . $this->viewer
            . ($context !== [] ? '.' . substr(sha1(serialize($context)), 0, 16) : '');
    }
}
