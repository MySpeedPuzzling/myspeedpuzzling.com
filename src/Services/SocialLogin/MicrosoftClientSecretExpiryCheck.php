<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Microsoft client secrets expire (24 months at most) and Microsoft warns
 * nobody: an expired secret = every Microsoft sign-in fails (AADSTS7000222).
 * MICROSOFT_CLIENT_SECRET_EXPIRES_AT (YYYY-MM-DD, copied from the portal's
 * "Expires" column) turns that into a reminder: from WARN_DAYS_BEFORE days
 * before the date a warning (-> Sentry), once expired an error - each at most
 * once per day (cache marker), so it never floods.
 *
 * Called at the end of every request (MicrosoftClientSecretExpirySubscriber):
 * outside the reminder window it costs one date comparison and no I/O, and no
 * cron row is needed.
 */
final readonly class MicrosoftClientSecretExpiryCheck
{
    public const int WARN_DAYS_BEFORE = 30;
    private const string MARKER_KEY_PREFIX = 'microsoft_secret_expiry_reminded_';
    private const int MARKER_TTL_SECONDS = 26 * 3600;

    public function __construct(
        private CacheItemPoolInterface $socialLoginStateCache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $microsoftClientId,
        private string $microsoftClientSecret,
        private string $microsoftClientSecretExpiresAt,
    ) {
    }

    public function check(): void
    {
        if ($this->microsoftClientSecretExpiresAt === '' || $this->microsoftClientId === '' || $this->microsoftClientSecret === '') {
            return;
        }

        $utc = new DateTimeZone('UTC');
        $now = $this->clock->now()->setTimezone($utc);
        $expiresAt = DateTimeImmutable::createFromFormat('!Y-m-d', $this->microsoftClientSecretExpiresAt, $utc);

        if ($expiresAt === false || $expiresAt->format('Y-m-d') !== $this->microsoftClientSecretExpiresAt) {
            $this->onceToday($now, fn () => $this->logger->warning('MICROSOFT_CLIENT_SECRET_EXPIRES_AT is not a YYYY-MM-DD date - no expiry reminder for the Microsoft client secret.', [
                'value' => $this->microsoftClientSecretExpiresAt,
            ]));

            return;
        }

        if ($now < $expiresAt->modify(sprintf('-%d days', self::WARN_DAYS_BEFORE))) {
            return;
        }

        if ($now >= $expiresAt) {
            $this->onceToday($now, fn () => $this->logger->error('Microsoft client secret has expired - Microsoft sign-in is down until MICROSOFT_CLIENT_SECRET is rotated (docs/features/auth-hardening/setup-microsoft.md).', [
                'expires_at' => $this->microsoftClientSecretExpiresAt,
            ]));

            return;
        }

        $daysLeft = (int) ceil(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);

        $this->onceToday($now, fn () => $this->logger->warning(sprintf(
            'Microsoft client secret expires in %d days - rotate MICROSOFT_CLIENT_SECRET (docs/features/auth-hardening/setup-microsoft.md).',
            $daysLeft,
        ), [
            'expires_at' => $this->microsoftClientSecretExpiresAt,
            'days_left' => $daysLeft,
        ]));
    }

    /**
     * @param callable(): void $report
     */
    private function onceToday(DateTimeImmutable $now, callable $report): void
    {
        $marker = $this->socialLoginStateCache->getItem(self::MARKER_KEY_PREFIX . $now->format('Ymd'));

        if ($marker->isHit()) {
            return;
        }

        // Claimed before reporting: a failing logger must not turn into a
        // report on every request
        $marker->set(true);
        $marker->expiresAfter(self::MARKER_TTL_SECONDS);
        $this->socialLoginStateCache->save($marker);

        $report();
    }
}
