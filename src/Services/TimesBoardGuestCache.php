<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The Times boards' rows for a viewer-less request (a guest: no own row, nobody blocked) are the same for everybody -
 * cached for a minute, so the /ladder overview and its lazy tables cost a guest no statement most of the time. Each
 * board statement rebuilds every player's best time (50-90 ms on 523k results); the guest responses already promise
 * caches `s-maxage=60`. A signed-in viewer (their own row, their blocklist) always reads the database.
 */
final readonly class TimesBoardGuestCache
{
    public const int TTL_SECONDS = 60;

    public function __construct(
        private CacheInterface $timesBoardGuestCache,
        private bool $enabled = true,
    ) {
    }

    /**
     * @param callable(): list<array<string, mixed>> $fetch
     * @return list<array<string, mixed>>
     */
    public function rows(bool $cacheable, string $key, callable $fetch): array
    {
        if ($cacheable === false || $this->enabled === false) {
            return $fetch();
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->timesBoardGuestCache->get('times_board_' . md5($key), static function (ItemInterface $item) use ($fetch): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $fetch();
        });

        return $rows;
    }
}
