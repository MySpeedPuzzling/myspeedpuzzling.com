<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\SweepOnlineMediaOrphans;
use SpeedPuzzling\Web\Query\GetOnlineMediaObjectKeys;
use SpeedPuzzling\Web\Results\SweptOnlineMediaOrphans;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Value\OnlineMediaObject;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One page of a store's `online/` objects (PAGE_SIZE keys, in key order): those older than MIN_AGE_SECONDS without
 * their row are deleted in one request - an evidence object without its evidence row, a round's reference image no
 * online round holds any more (GetOnlineMediaObjectKeys: a held reference image is never an orphan). Where the listing stopped is kept in the shared cache, so the next page -
 * in this run or the next one - goes on from there, and a big bucket is covered over several runs; a complete listing
 * starts over. Losing that marker only means starting over.
 *
 * Nothing here stops the prune: a store that does not answer is a warning and an empty page, a key the store would not
 * delete is a warning and stays for a later sweep. No row is touched - the transaction holds one read.
 */
#[AsMessageHandler]
readonly final class SweepOnlineMediaOrphansHandler
{
    public const string PREFIX = 'online/';
    public const int PAGE_SIZE = 1_000;
    // An upload's row is made before its URL - a day keeps far away from any upload in flight
    public const int MIN_AGE_SECONDS = 86_400;
    public const string MARKER_KEY_PREFIX = 'online_media_orphan_sweep.';
    private const int MARKER_TTL_SECONDS = 30 * 86_400;

    public function __construct(
        private OnlineMediaStorage $onlineMediaStorage,
        private OnlineMediaStores $onlineMediaStores,
        private GetOnlineMediaObjectKeys $getOnlineMediaObjectKeys,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SweepOnlineMediaOrphans $message): SweptOnlineMediaOrphans
    {
        $store = $message->store;

        if ($store === OnlineMediaStore::Secondary && $this->onlineMediaStores->hasSecondary() === false) {
            return new SweptOnlineMediaOrphans(0, 0, 0, 0, false);
        }

        $marker = $this->cache->getItem(self::MARKER_KEY_PREFIX . $store->value);
        $startAfter = $marker->isHit() && is_string($marker->get()) ? $marker->get() : null;

        try {
            $list = $this->onlineMediaStorage->listObjects($store, self::PREFIX, $startAfter, self::PAGE_SIZE);
        } catch (\Throwable $e) {
            $this->logger->warning('Listing online evidence for the orphan sweep failed - swept another day', [
                'store' => $store->value,
                'exception' => $e,
            ]);

            return new SweptOnlineMediaOrphans(0, 0, 0, 0, false);
        }

        $before = $this->clock->now()->getTimestamp() - self::MIN_AGE_SECONDS;
        $oldKeys = array_values(array_map(
            static fn (OnlineMediaObject $object): string => $object->key,
            array_filter(
                $list->objects,
                static fn (OnlineMediaObject $object): bool => $object->lastModified !== null && $object->lastModified->getTimestamp() < $before,
            ),
        ));

        $withRow = array_flip($this->getOnlineMediaObjectKeys->existing($oldKeys));
        $orphans = array_values(array_filter($oldKeys, static fn (string $key): bool => isset($withRow[$key]) === false));
        $failed = [];

        if ($orphans !== []) {
            try {
                $failed = $this->onlineMediaStorage->deleteObjects($store, $orphans);
            } catch (\Throwable $e) {
                $failed = $orphans;
                $this->logger->warning('Deleting orphaned online evidence failed - swept another day', [
                    'store' => $store->value,
                    'keys' => count($orphans),
                    'exception' => $e,
                ]);
            }

            if ($failed !== [] && count($failed) < count($orphans)) {
                $this->logger->warning('The store kept some orphaned online evidence - swept another day', [
                    'store' => $store->value,
                    'keys' => array_slice($failed, 0, 20),
                ]);
            }

            $this->logger->info('Orphaned online evidence deleted', [
                'store' => $store->value,
                'deleted' => count($orphans) - count($failed),
            ]);
        }

        if ($list->nextStartAfter === null) {
            $this->cache->deleteItem(self::MARKER_KEY_PREFIX . $store->value);
        } else {
            $marker->set($list->nextStartAfter);
            $marker->expiresAfter(self::MARKER_TTL_SECONDS);
            $this->cache->save($marker);
        }

        return new SweptOnlineMediaOrphans(
            listed: count($list->objects),
            orphans: count($orphans),
            deleted: count($orphans) - count($failed),
            failed: count($failed),
            hasMore: $list->nextStartAfter !== null,
        );
    }
}
