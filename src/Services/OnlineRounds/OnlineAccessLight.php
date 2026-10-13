<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Value\OnlineAccessLightContext;
use SpeedPuzzling\Web\Value\OnlineToken;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The heartbeat's view of a personal link (`POST /api/compete/{token}/heartbeat`) - what OnlineAccess knows, reduced
 * to ids and kept in the shared cache (`cache.app`), so a phone beating every 15 s costs no statement:
 * - per token, for TTL_SECONDS under `online_access_light.<token>`: the participant, their competition, their current
 *   round, their entry in it and its active device. A new link deletes the old token's key once RegenerateOnlineToken
 *   is committed (forget(), from online_link_regenerate - contract §6: this class holds the one definition of the key);
 *   a `device.ready` that hands the entry to another device deletes it too (ApiEventsController) - the old device
 *   learns it was taken over on its next beat. Anything else (a round that starts being the current one, a removed
 *   participant) shows within TTL_SECONDS.
 * - per round, for ROUND_HASH_TTL_SECONDS: the round clock's state hash (RoundClock::stateHash()), shared by every phone
 *   of the round - the heartbeat's hint to fetch the state again is at most that much late, and the round's row is
 *   read once per that time whatever the number of phones.
 * - per unknown token, for UNKNOWN_TTL_SECONDS: that a lookup found nothing (isUnknown() / rememberUnknown(), also
 *   used by OnlineParticipantApi for the other endpoints) - a link asked for again and again costs no statement.
 */
final readonly class OnlineAccessLight
{
    public const string CACHE_KEY_PREFIX = 'online_access_light.';
    public const int TTL_SECONDS = 60;
    public const string ROUND_HASH_KEY_PREFIX = 'online_round_state_hash.';
    public const int ROUND_HASH_TTL_SECONDS = 2;
    // A token a lookup found nothing for: answered without the database for a few seconds (every endpoint)
    public const string UNKNOWN_KEY_PREFIX = 'online_access_unknown.';
    public const int UNKNOWN_TTL_SECONDS = 10;

    public function __construct(
        private CacheInterface $cache,
        private OnlineAccess $onlineAccess,
        private OnlineEntryRepository $onlineEntryRepository,
        private CompetitionRoundRepository $competitionRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OnlineLinkNotFound
     */
    public function forToken(string $token): OnlineAccessLightContext
    {
        if (OnlineToken::looksValid($token) === false) {
            throw new OnlineLinkNotFound();
        }

        try {
            $context = OnlineAccessLightContext::fromArray($this->cache->get(self::cacheKey($token), $this->compute($token)));

            if ($context === null) {
                // Written by another version of this code - read again
                $this->cache->delete(self::cacheKey($token));
                $context = OnlineAccessLightContext::fromArray($this->cache->get(self::cacheKey($token), $this->compute($token)));
            }
        } catch (OnlineLinkNotFound $exception) {
            $this->rememberUnknown($token);

            throw $exception;
        }

        return $context ?? throw new OnlineLinkNotFound();
    }

    /**
     * A lookup found nothing for this token within UNKNOWN_TTL_SECONDS - answered without the database
     */
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

    /**
     * The next heartbeat of this link reads everything again
     */
    public function forget(string $token): void
    {
        if (OnlineToken::looksValid($token)) {
            $this->cache->delete(self::cacheKey($token));
        }
    }

    public static function cacheKey(string $token): string
    {
        return self::CACHE_KEY_PREFIX . $token;
    }

    /**
     * The round clock's state hash, at most ROUND_HASH_TTL_SECONDS old - null for a round deleted meanwhile
     */
    public function roundStateHash(string $roundId): null|string
    {
        try {
            $hash = $this->cache->get(self::ROUND_HASH_KEY_PREFIX . strtolower($roundId), function (ItemInterface $item) use ($roundId): string {
                $item->expiresAfter(self::ROUND_HASH_TTL_SECONDS);

                return RoundClock::fromRound($this->competitionRoundRepository->get($roundId), $this->clock->now())->stateHash();
            });
        } catch (CompetitionRoundNotFound) {
            return null;
        }

        return $hash;
    }

    /**
     * @return callable(ItemInterface): array<string, null|string>
     */
    private function compute(string $token): callable
    {
        return function (ItemInterface $item) use ($token): array {
            $item->expiresAfter(self::TTL_SECONDS);

            // Unknown and removed links throw - nothing is cached for them, only that they are unknown (forToken())
            if ($this->isUnknown($token)) {
                throw new OnlineLinkNotFound();
            }

            $access = $this->onlineAccess->forToken($token);
            $onlineEntry = $access->entry === null ? null : $this->onlineEntryRepository->ofEntry($access->entry->id->toString());

            return (new OnlineAccessLightContext(
                participantId: $access->participant->id->toString(),
                competitionId: $access->competition->id->toString(),
                roundId: $access->round?->id->toString(),
                entryId: $access->entry?->id->toString(),
                activeDeviceId: $onlineEntry?->activeDeviceId,
            ))->toArray();
        };
    }
}
