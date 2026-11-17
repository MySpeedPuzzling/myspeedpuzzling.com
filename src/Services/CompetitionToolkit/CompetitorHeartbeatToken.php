<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use DateTimeImmutable;
use SensitiveParameter;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatClaims;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The right to send heartbeats for one's own round entry (reliability hardening T2, CompetitorHeartbeatController):
 * handed out in the competitor state JSON only on the signed-in competitor's own entry with time proposals on
 * (`heartbeat` on the round, GetCompetitorPage), so the heartbeat endpoint needs no session, no user and no database -
 * an HMAC (APP_SECRET) over the round, the entry, the player and the expiry.
 *
 * `{roundId}.{entry}.{playerId}.{expiresAt (unix s)}.{signature}` - the signature is the first 32 hex characters (128
 * bits) of HMAC-SHA256. LIFETIME_SECONDS from the state that issued it; every state fetch hands out a fresh one, and a
 * phone with an expired one asks for the state (403 `expired_token`). Nothing here is secret but the signature: the
 * token names nothing the competitor does not know.
 */
readonly final class CompetitorHeartbeatToken
{
    public const int LIFETIME_SECONDS = 6 * 3600;
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    public function issue(string $roundId, string $entry, string $playerId, DateTimeImmutable $now): string
    {
        $payload = strtolower($roundId) . '.' . strtolower($entry) . '.' . strtolower($playerId) . '.' . ($now->getTimestamp() + self::LIFETIME_SECONDS);

        return $payload . '.' . $this->signature($payload);
    }

    /**
     * The claims of a valid token - null for anything else; `expired` set when it was valid once.
     */
    public function verify(string $token, int $nowMs): null|CompetitorHeartbeatClaims
    {
        $pattern = '/^(' . self::UUID . ')\.((?:participant_round|team):' . self::UUID . ')\.(' . self::UUID . ')\.(\d{1,12})\.([0-9a-f]{32})$/';

        if (strlen($token) > 200 || preg_match($pattern, $token, $matches) !== 1) {
            return null;
        }

        $payload = $matches[1] . '.' . $matches[2] . '.' . $matches[3] . '.' . $matches[4];

        if (!hash_equals($this->signature($payload), $matches[5])) {
            return null;
        }

        return new CompetitorHeartbeatClaims(
            roundId: $matches[1],
            entry: $matches[2],
            playerId: $matches[3],
            expired: (int) $matches[4] * 1000 < $nowMs,
        );
    }

    private function signature(string $payload): string
    {
        return substr(hash_hmac('sha256', 'competitor-heartbeat|' . $payload, $this->secret), 0, 32);
    }
}
