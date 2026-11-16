<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs the trusted-human cookie (__bb_trust) that the Traefik bot-blocker
 * sidecar validates: an eligible logged-in account (GetBotTrustEligibility -
 * a bare, unverified, brand-new one is not) is the strongest "not a bot" signal
 * we have, and the cookie lets the edge middleware skip every heuristic for
 * this browser. The app only ISSUES the cookie; enforcement lives in the
 * bot-blocker (MySpeedPuzzling/bot-blocker-middleware).
 *
 * Wire format — MUST stay byte-identical with the sidecar's getTrustedUid()
 * (both sides pin it with the same golden-vector test):
 *
 *   base64url("bb-trust|v1|<uid>|<issuedAtMs>") . "." . base64url(HMAC-SHA256 raw)
 *
 * The secret is CHALLENGE_COOKIE_SECRET — the same one signing the sidecar's
 * __bb_pass challenge cookie. Sharing it is safe because the HMAC payloads
 * are domain-separated ("bb-trust|v1|..." vs "<ip>|<expires>"), and it means
 * production needs no new secret: the app's .env already carries it.
 * When the secret is empty (local dev, CI) the whole feature is disabled.
 */
readonly final class BotTrustCookieSigner
{
    public const string COOKIE_NAME = '__bb_trust';

    /**
     * The browser's expiry of a minted cookie. The sidecar judges the issued-at in the payload against its own
     * lifetime setting - a value replayed outside a browser lives as long as the sidecar allows, not this.
     */
    public const int LIFETIME_DAYS = 90;

    /**
     * Re-mint on authenticated responses once the cookie is older than this - and re-check the account's
     * eligibility (GetBotTrustEligibility) on the way, so trust follows the account within a week.
     */
    public const int REFRESH_AFTER_DAYS = 7;

    /**
     * 2026-11-17T00:00:00Z in ms. Cookies issued before it were minted for ANY signed-in account (no eligibility
     * rule yet), so they are treated as stale: the next authenticated response re-checks the account and re-mints
     * the cookie or expires it.
     */
    public const int TRUST_REVALIDATE_ISSUED_BEFORE = 1_794_873_600_000;

    public function __construct(
        #[Autowire(env: 'CHALLENGE_COOKIE_SECRET')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->secret !== '';
    }

    /**
     * Opaque, stable, PII-free browser owner id: the sidecar logs it so one
     * account fanning out over many IPs (a registered scraper) is visible,
     * without ever putting the identifier itself inside a readable cookie.
     */
    public static function uidFor(string $userIdentifier): string
    {
        return substr(hash('sha256', $userIdentifier), 0, 16);
    }

    /**
     * Issued now in real time, never the app's clock: the sidecar validates the issued-at against its own clock
     * (docs/features/time-and-clock).
     */
    public function build(string $uid): string
    {
        // @phpstan-ignore clock.systemTime (the bot-blocker sidecar validates the issued-at on its own clock)
        return $this->buildAt($uid, (int) new DateTimeImmutable()->format('Uv'));
    }

    public function buildAt(string $uid, int $issuedAtMs): string
    {
        $payload = sprintf('bb-trust|v1|%s|%d', $uid, $issuedAtMs);

        return self::base64UrlEncode($payload) . '.' . self::base64UrlEncode($this->sign($payload));
    }

    /**
     * Returns the issued-at (ms epoch) of a valid cookie belonging to the
     * given uid, or null for anything invalid, foreign or malformed. The
     * subscriber uses this to decide between "still fresh" and "re-mint".
     */
    public function parseIssuedAt(#[SensitiveParameter] string $cookieValue, string $expectedUid): null|int
    {
        $parts = explode('.', $cookieValue);

        if (count($parts) !== 2) {
            return null;
        }

        $payload = self::base64UrlDecode($parts[0]);
        $signature = self::base64UrlDecode($parts[1]);

        if ($payload === null || $signature === null || !hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $fields = explode('|', $payload);

        if (count($fields) !== 4 || $fields[0] !== 'bb-trust' || $fields[1] !== 'v1') {
            return null;
        }

        if ($fields[2] !== $expectedUid || ctype_digit($fields[3]) === false) {
            return null;
        }

        return (int) $fields[3];
    }

    /**
     * A valid cookie of this account needs a refresh (re-check eligibility, then re-mint or expire) once it is
     * older than REFRESH_AFTER_DAYS or was issued before TRUST_REVALIDATE_ISSUED_BEFORE. Both instants are real
     * time (issued-at from build(), now from the caller), never the app's clock.
     */
    public static function needsRefresh(int $issuedAtMs, int $nowMs): bool
    {
        if ($issuedAtMs < self::TRUST_REVALIDATE_ISSUED_BEFORE) {
            return true;
        }

        return $nowMs - $issuedAtMs >= self::REFRESH_AFTER_DAYS * 24 * 60 * 60 * 1000;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, binary: true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): null|string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }
}
