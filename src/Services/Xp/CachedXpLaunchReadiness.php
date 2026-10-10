<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use DateTimeImmutable;
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
    private const string CACHE_KEY = 'xp_launch_ready_at';
    private const int READY_TTL_SECONDS = 300;
    private const int NOT_READY_TTL_SECONDS = 30;

    /** The marker as stored, '' while the switch is off; null = not read in this request yet */
    private null|string $readyAt = null;

    public function __construct(
        readonly private Connection $database,
        readonly private CacheInterface $xpLaunchReadinessCache,
    ) {
    }

    public function isReady(): bool
    {
        return $this->marker() !== '';
    }

    public function readyAt(): null|DateTimeImmutable
    {
        $marker = $this->marker();

        return $marker === '' ? null : new DateTimeImmutable($marker);
    }

    private function marker(): string
    {
        return $this->readyAt ??= $this->xpLaunchReadinessCache->get(self::CACHE_KEY, function (ItemInterface $item): string {
            $value = $this->database->fetchOne('SELECT launch_ready_at FROM xp_settings WHERE id = 1');
            $marker = is_string($value) ? $value : '';
            $item->expiresAfter($marker !== '' ? self::READY_TTL_SECONDS : self::NOT_READY_TTL_SECONDS);

            return $marker;
        });
    }

    public function forget(): void
    {
        $this->xpLaunchReadinessCache->delete(self::CACHE_KEY);
        $this->readyAt = null;
    }

    public function reset(): void
    {
        $this->readyAt = null;
    }
}
