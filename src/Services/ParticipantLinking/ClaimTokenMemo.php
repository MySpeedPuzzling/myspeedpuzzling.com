<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Claim tokens a lookup found nothing valid for, remembered for UNKNOWN_TTL_SECONDS in the shared cache (`cache.app`) -
 * the claim page asked again and again for a wrong or cleared link costs no statement (docs/features/competitions-
 * management/participant-linking.md §6.5, the OnlineAccessLight pattern). A malformed token is unknown without asking.
 */
final readonly class ClaimTokenMemo
{
    public const string UNKNOWN_KEY_PREFIX = 'participant_claim_unknown.';
    public const int UNKNOWN_TTL_SECONDS = 10;

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function isUnknown(string $token): bool
    {
        if (OnlineToken::looksValid($token) === false) {
            return true;
        }

        // A peek: nothing is stored on a miss
        return $this->cache->get(self::UNKNOWN_KEY_PREFIX . $token, static function (ItemInterface $item, bool &$save): bool {
            $save = false;

            return false;
        }) === true;
    }

    public function rememberUnknown(string $token): void
    {
        if (OnlineToken::looksValid($token) === false) {
            return;
        }

        $this->cache->delete(self::UNKNOWN_KEY_PREFIX . $token);
        $this->cache->get(self::UNKNOWN_KEY_PREFIX . $token, static function (ItemInterface $item): bool {
            $item->expiresAfter(self::UNKNOWN_TTL_SECONDS);

            return true;
        });
    }
}
