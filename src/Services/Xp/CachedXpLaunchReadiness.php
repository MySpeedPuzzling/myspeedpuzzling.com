<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * XpLaunchReadiness read from `xp_settings.launch_ready_at` (+ `full_formula_from`, where the launch backfill's window
 * starts).
 *
 * No query per page view: the answer sits in the shared cache pool `xp_launch_readiness_cache` (Redis - every worker
 * and container sees the same entry) and is kept for the request. MarkXpLaunchReady's caller drops the entry
 * (forget()), so the switch reaches every worker at once; the TTL only bounds how long a hand-made rollback takes.
 */
final class CachedXpLaunchReadiness implements XpLaunchReadiness, ResetInterface
{
    // 'xp_launch_ready_at' held the marker alone - a new key, so an entry of the previous build is never misread
    private const string CACHE_KEY = 'xp_launch_window';
    private const int READY_TTL_SECONDS = 300;
    private const int NOT_READY_TTL_SECONDS = 30;

    /** "launch_ready_at|full_formula_from" as stored, '' while the switch is off; null = not read in this request yet */
    private null|string $window = null;

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

        return $marker === '' ? null : new DateTimeImmutable(explode('|', $marker)[0]);
    }

    public function stampedFrom(): null|DateTimeImmutable
    {
        $from = explode('|', $this->marker())[1] ?? '';

        return $from === '' ? null : new DateTimeImmutable($from);
    }

    private function marker(): string
    {
        return $this->window ??= $this->xpLaunchReadinessCache->get(self::CACHE_KEY, function (ItemInterface $item): string {
            $row = $this->database->fetchAssociative('SELECT launch_ready_at, full_formula_from FROM xp_settings WHERE id = 1');
            $readyAt = $row !== false && is_string($row['launch_ready_at']) ? $row['launch_ready_at'] : '';
            $from = $row !== false && is_string($row['full_formula_from']) ? $row['full_formula_from'] : '';
            $marker = $readyAt !== '' ? $readyAt . '|' . $from : '';
            $item->expiresAfter($marker !== '' ? self::READY_TTL_SECONDS : self::NOT_READY_TTL_SECONDS);

            return $marker;
        });
    }

    public function forget(): void
    {
        $this->xpLaunchReadinessCache->delete(self::CACHE_KEY);
        $this->window = null;
    }

    public function reset(): void
    {
        $this->window = null;
    }
}
