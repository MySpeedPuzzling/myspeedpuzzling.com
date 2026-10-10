<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * XpLaunchReadiness read from `xp_settings.launch_ready_at`.
 *
 * No query per page view: the answer sits in the shared cache pool `xp_launch_readiness_cache` (Redis - every worker
 * and container sees the same entry) and is kept for the request. MarkXpLaunchReady's caller drops the entry
 * (forget()), so the switch reaches every worker at once; the TTL only bounds how long a hand-made rollback takes.
 */
final class CachedXpLaunchReadiness implements XpLaunchReadiness, ResetInterface
{
    private const string CACHE_KEY = 'xp_launch_ready';
    private const int READY_TTL_SECONDS = 300;
    private const int NOT_READY_TTL_SECONDS = 30;

    private null|bool $ready = null;

    public function __construct(
        readonly private Connection $database,
        readonly private CacheInterface $xpLaunchReadinessCache,
    ) {
    }

    public function isReady(): bool
    {
        return $this->ready ??= $this->xpLaunchReadinessCache->get(self::CACHE_KEY, function (ItemInterface $item): bool {
            $value = $this->database->fetchOne('SELECT launch_ready_at FROM xp_settings WHERE id = 1');
            $ready = is_string($value);
            $item->expiresAfter($ready ? self::READY_TTL_SECONDS : self::NOT_READY_TTL_SECONDS);

            return $ready;
        });
    }

    public function forget(): void
    {
        $this->xpLaunchReadinessCache->delete(self::CACHE_KEY);
        $this->ready = null;
    }

    public function reset(): void
    {
        $this->ready = null;
    }
}
