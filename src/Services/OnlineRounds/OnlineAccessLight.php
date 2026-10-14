<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Value\OnlineAccessLightContext;
use SpeedPuzzling\Web\Value\OnlineParticipantSettings;
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
 * - per token, for GENERATION_TTL_SECONDS after a forget(): the link's cache generation (a random id). A lookup stores
 *   the generation it read before asking the database, and one stored under another generation is read again: a
 *   heartbeat that read the link while another device's `device.ready` was committing writes its (old) answer only
 *   after forget() deleted the key - without the generation that stale answer would stay for TTL_SECONDS and the newly
 *   claiming device would be told to give way. Costs one cache read per heartbeat, never a statement.
 * - per round, for ROUND_HASH_TTL_SECONDS: the round clock's state hash (RoundClock::stateHash()) and the hash of its
 *   online timing settings the phones use (OnlineParticipantSettings::hash()), shared by every phone of the round - the
 *   heartbeat's hint to fetch the state again is at most that much late, and the round's rows are read once per that
 *   time whatever the number of phones (a link looked up anew fills it from what it read anyway). A saved settings
 *   change deletes it once committed (forgetRound(), OnlineRoundSettingsLiveUpdates) - the next heartbeat names the new
 *   hash. A lookup that read the rows before the commit and stores its hashes after the delete keeps the old ones for at
 *   most ROUND_HASH_TTL_SECONDS: a phone that already read the new state fetches it once more, one that did not hears of
 *   the change from the push (or its next heartbeat) - never a wrong state, so no generation as for the links.
 * - per unknown token, for UNKNOWN_TTL_SECONDS: that a lookup found nothing (isUnknown() / rememberUnknown(), also
 *   used by OnlineParticipantApi for the other endpoints) - a link asked for again and again costs no statement.
 */
final readonly class OnlineAccessLight
{
    public const string CACHE_KEY_PREFIX = 'online_access_light.';
    public const int TTL_SECONDS = 60;
    // Both hashes of a round ({stateHash, settingsHash}) - not the old key of the state hash alone: during a blue-green
    // deploy the previous release still reads that one as a string
    public const string ROUND_HASH_KEY_PREFIX = 'online_round_hashes.';
    public const int ROUND_HASH_TTL_SECONDS = 2;
    // A token a lookup found nothing for: answered without the database for a few seconds (every endpoint)
    public const string UNKNOWN_KEY_PREFIX = 'online_access_unknown.';
    public const int UNKNOWN_TTL_SECONDS = 10;
    // The link's cache generation after a forget(): outlives every lookup read before it (TTL_SECONDS)
    public const string GENERATION_KEY_PREFIX = 'online_access_light_generation.';
    public const int GENERATION_TTL_SECONDS = 2 * self::TTL_SECONDS;

    public function __construct(
        private CacheInterface $cache,
        private OnlineAccess $onlineAccess,
        private OnlineEntryRepository $onlineEntryRepository,
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
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

        // Read before the database is asked - a lookup is never newer than the generation it carries
        $generation = $this->generation($token);

        try {
            $context = OnlineAccessLightContext::fromArray($this->cache->get(self::cacheKey($token), $this->compute($token, $generation)));

            if ($context === null || $context->generation !== $generation) {
                // Written by another version of this code, or read before the link was last forgotten - read again
                $this->cache->delete(self::cacheKey($token));
                $context = OnlineAccessLightContext::fromArray($this->cache->get(self::cacheKey($token), $this->compute($token, $generation)));
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
     * The next heartbeat of this link reads everything again - and no lookup read before now is used any more, even one
     * written to the cache after this (a new generation). Called once the change is committed.
     */
    public function forget(string $token): void
    {
        if (OnlineToken::looksValid($token) === false) {
            return;
        }

        $generation = Uuid::uuid7()->toString();
        $this->cache->delete(self::GENERATION_KEY_PREFIX . $token);
        $this->cache->get(self::GENERATION_KEY_PREFIX . $token, static function (ItemInterface $item) use ($generation): string {
            $item->expiresAfter(self::GENERATION_TTL_SECONDS);

            return $generation;
        });
        $this->cache->delete(self::cacheKey($token));
    }

    /**
     * The link's cache generation - null when it was not forgotten lately (a peek: nothing is stored on a miss)
     */
    private function generation(string $token): null|string
    {
        /** @var mixed $generation the shared cache - whatever is there */
        $generation = $this->cache->get(self::GENERATION_KEY_PREFIX . $token, static function (ItemInterface $item, bool &$save): mixed {
            $save = false;

            return null;
        });

        return is_string($generation) ? $generation : null;
    }

    public static function cacheKey(string $token): string
    {
        return self::CACHE_KEY_PREFIX . $token;
    }

    /**
     * The round clock's state hash and its settings hash, at most ROUND_HASH_TTL_SECONDS old - null for a round deleted
     * meanwhile; `settingsHash` null for a round without online timing
     *
     * @return null|array{stateHash: string, settingsHash: null|string}
     */
    public function roundHashes(string $roundId): null|array
    {
        $key = self::roundHashKey($roundId);

        try {
            $hashes = self::readHashes($this->cache->get($key, $this->computeHashes($roundId)));

            if ($hashes === null) {
                // Written by another version of this code - read again
                $this->cache->delete($key);
                $hashes = self::readHashes($this->cache->get($key, $this->computeHashes($roundId)));
            }
        } catch (CompetitionRoundNotFound) {
            return null;
        }

        return $hashes;
    }

    /**
     * The next heartbeat of the round's phones reads its hashes again - called once a change of its settings is
     * committed (OnlineRoundSettingsLiveUpdates)
     */
    public function forgetRound(string $roundId): void
    {
        $this->cache->delete(self::roundHashKey($roundId));
    }

    public static function roundHashKey(string $roundId): string
    {
        return self::ROUND_HASH_KEY_PREFIX . strtolower($roundId);
    }

    /**
     * @return callable(ItemInterface): array{stateHash: string, settingsHash: null|string}
     */
    private function computeHashes(string $roundId): callable
    {
        return function (ItemInterface $item) use ($roundId): array {
            $item->expiresAfter(self::ROUND_HASH_TTL_SECONDS);
            // One statement: the online round with its round
            $onlineRound = $this->onlineRoundRepository->ofRound($roundId);

            if ($onlineRound !== null) {
                return self::hashes($onlineRound->round, $onlineRound, $this->clock->now());
            }

            return self::hashes($this->competitionRoundRepository->get($roundId), null, $this->clock->now());
        };
    }

    /**
     * @return null|array{stateHash: string, settingsHash: null|string}
     */
    private static function readHashes(mixed $cached): null|array
    {
        if (!is_array($cached) || !is_string($cached['stateHash'] ?? null)) {
            return null;
        }

        $settingsHash = $cached['settingsHash'] ?? null;

        return ['stateHash' => $cached['stateHash'], 'settingsHash' => is_string($settingsHash) ? $settingsHash : null];
    }

    /**
     * @return array{stateHash: string, settingsHash: null|string}
     */
    private static function hashes(CompetitionRound $round, null|OnlineRound $onlineRound, DateTimeImmutable $now): array
    {
        return [
            // Of the stored values only - the moment does not change it (RoundClock::stateHash())
            'stateHash' => RoundClock::fromRound($round, $now)->stateHash(),
            'settingsHash' => $onlineRound === null ? null : OnlineParticipantSettings::of($onlineRound)->hash(),
        ];
    }

    /**
     * @return callable(ItemInterface): array<string, null|string>
     */
    private function compute(string $token, null|string $generation): callable
    {
        return function (ItemInterface $item) use ($token, $generation): array {
            $item->expiresAfter(self::TTL_SECONDS);

            // Unknown and removed links throw - nothing is cached for them, only that they are unknown (forToken())
            if ($this->isUnknown($token)) {
                throw new OnlineLinkNotFound();
            }

            $access = $this->onlineAccess->forToken($token);
            $onlineEntry = $access->entry === null ? null : $this->onlineEntryRepository->ofEntry($access->entry->id->toString());

            // The round's hashes from the rows just read - the heartbeat asking for them next costs no statement
            if ($access->round !== null && $access->onlineRound !== null) {
                $round = $access->round;
                $onlineRound = $access->onlineRound;
                $now = $this->clock->now();
                $this->cache->get(self::roundHashKey($round->id->toString()), static function (ItemInterface $item) use ($round, $onlineRound, $now): array {
                    $item->expiresAfter(self::ROUND_HASH_TTL_SECONDS);

                    return self::hashes($round, $onlineRound, $now);
                });
            }

            return (new OnlineAccessLightContext(
                participantId: $access->participant->id->toString(),
                competitionId: $access->competition->id->toString(),
                roundId: $access->round?->id->toString(),
                entryId: $access->entry?->id->toString(),
                activeDeviceId: $onlineEntry?->activeDeviceId,
                generation: $generation,
            ))->toArray();
        };
    }
}
