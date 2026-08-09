<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Wjpf;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Single-use authorization codes for the manual pairing flow.
 *
 * The redirect back to WJPF carries a code, never the player id. A player id in the URL would
 * be forgeable - ours appear in public API paths, so anyone could hand WJPF somebody else's
 * id and link their own WJPF account to that person's profile. A code is only ever issued to
 * a browser that has just authenticated, so the worst it can do is link the account that
 * asked for it.
 *
 * The code is a bearer credential, so only its hash is used as the cache key: a dump of the
 * cache yields nothing redeemable.
 */
readonly final class WjpfPairingCodeStore
{
    /** Long enough to survive a slow redirect chain, short enough that a leaked URL goes stale. */
    private const int TTL_SECONDS = 600;

    private const string KEY_PREFIX = 'wjpf_pairing_code_';

    public function __construct(
        private CacheItemPoolInterface $wjpfPairingCodeCache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function issue(string $playerId): string
    {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $item = $this->wjpfPairingCodeCache->getItem(self::key($code));
        $item->set($playerId);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->wjpfPairingCodeCache->save($item);

        return $code;
    }

    /**
     * Redeems the code and burns it. Returns null when unknown, expired or already used.
     *
     * @throws InvalidArgumentException
     */
    public function consume(string $code): null|string
    {
        $key = self::key($code);
        $item = $this->wjpfPairingCodeCache->getItem($key);

        if ($item->isHit() === false) {
            return null;
        }

        // Delete before returning: a replayed code must never resolve twice, even if the
        // caller crashes between here and recording the pairing.
        $this->wjpfPairingCodeCache->deleteItem($key);

        $playerId = $item->get();

        return is_string($playerId) ? $playerId : null;
    }

    private static function key(string $code): string
    {
        return self::KEY_PREFIX . hash('sha256', $code);
    }
}
