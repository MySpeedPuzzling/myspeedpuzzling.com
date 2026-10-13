<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Doctrine\UuidType;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Message\PruneOnlineMedia;
use SpeedPuzzling\Web\Results\PrunedOnlineMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Online competition evidence past its retention (docs/features/online-competitions/README.md §8, §13.6): periodic
 * photos after `olderThanDays` (the cron passes 30), everything else - start, finish and end photos, bursts, the empty
 * mat - after EVIDENCE_RETENTION_DAYS (12 months: disputes, records), counted from when the row was made.
 *
 * Never wedges:
 * - one page per message, oldest first by (created_at, id), at most PAGE_SIZE storage requests inside its transaction;
 *   the answer says where the next page starts - past every row of this one, deleted or not;
 * - each object on its own: a delete that fails keeps its row (a warning; the next run tries again), the rows of the
 *   deleted ones go; a store failing MAX_FAILURES_IN_A_ROW times in a row is down - the page stops (`storeFailing`)
 *   and the command with it;
 * - a row of the second store while none is configured is left alone, with a warning.
 */
#[AsMessageHandler]
readonly final class PruneOnlineMediaHandler
{
    public const int EVIDENCE_RETENTION_DAYS = 365;
    public const int PAGE_SIZE = 50;
    public const int MAX_FAILURES_IN_A_ROW = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OnlineMediaStorage $onlineMediaStorage,
        private OnlineMediaStores $onlineMediaStores,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PruneOnlineMedia $message): PrunedOnlineMedia
    {
        $now = $this->clock->now();
        $periodicBefore = $now->modify(sprintf('-%d days', max(1, $message->olderThanDays)));
        $evidenceBefore = $now->modify(sprintf('-%d days', max(self::EVIDENCE_RETENTION_DAYS, $message->olderThanDays)));

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('media')
            ->from(OnlineEntryMedia::class, 'media')
            ->where('(media.kind = :periodic AND media.createdAt < :periodicBefore) OR (media.kind <> :periodic AND media.createdAt < :evidenceBefore)')
            ->setParameter('periodic', OnlineMediaKind::Periodic)
            ->setParameter('periodicBefore', $periodicBefore)
            ->setParameter('evidenceBefore', $evidenceBefore)
            ->orderBy('media.createdAt', 'ASC')
            ->addOrderBy('media.id', 'ASC')
            ->setMaxResults(self::PAGE_SIZE);

        if ($message->afterCreatedAt !== null && $message->afterId !== null) {
            $queryBuilder
                ->andWhere('media.createdAt > :afterCreatedAt OR (media.createdAt = :afterCreatedAt AND media.id > :afterId)')
                ->setParameter('afterCreatedAt', $message->afterCreatedAt)
                ->setParameter('afterId', $message->afterId, UuidType::NAME);
        }

        /** @var list<OnlineEntryMedia> $page */
        $page = $queryBuilder->getQuery()->getResult();

        $deleted = [];
        $periodic = 0;
        $evidence = 0;
        $failed = 0;
        $failuresInARow = 0;
        $storeFailing = false;
        $skippedIds = [];

        foreach ($page as $media) {
            if ($media->store === OnlineMediaStore::Secondary && $this->onlineMediaStores->hasSecondary() === false) {
                $skippedIds[] = $media->id->toString();

                continue;
            }

            try {
                $this->onlineMediaStorage->delete($media);
            } catch (\Throwable $e) {
                $failed++;
                $failuresInARow++;
                $this->logger->warning('Pruning an online evidence object failed - its row stays for the next run', [
                    'media_id' => $media->id->toString(),
                    'store' => $media->store->value,
                    'exception' => $e,
                ]);

                if ($failuresInARow >= self::MAX_FAILURES_IN_A_ROW) {
                    $storeFailing = true;

                    break;
                }

                continue;
            }

            $failuresInARow = 0;
            $deleted[] = $media;

            if ($media->kind === OnlineMediaKind::Periodic) {
                $periodic++;
            } else {
                $evidence++;
            }
        }

        if ($skippedIds !== []) {
            $this->logger->warning('Online evidence in the second store while none is configured - left alone', [
                'media_ids' => $skippedIds,
            ]);
        }

        if ($deleted !== []) {
            // The prune exception to "no bulk SQL" (CLAUDE.md, State-Changing Operations Pattern): a cron deleting a
            // page of rows that nothing else reads any more - one statement instead of a DELETE per row
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM online_entry_media WHERE id IN (:ids)',
                ['ids' => array_map(static fn (OnlineEntryMedia $item): string => $item->id->toString(), $deleted)],
                ['ids' => ArrayParameterType::STRING],
            );

            // Deleted behind the unit of work's back - never written again
            foreach ($deleted as $item) {
                $this->entityManager->detach($item);
            }
        }

        $last = $page === [] ? null : $page[count($page) - 1];
        $full = count($page) === self::PAGE_SIZE;

        return new PrunedOnlineMedia(
            periodic: $periodic,
            evidence: $evidence,
            failed: $failed,
            skipped: count($skippedIds),
            nextAfterCreatedAt: $full ? $last?->createdAt : null,
            nextAfterId: $full ? $last?->id->toString() : null,
            storeFailing: $storeFailing,
        );
    }
}
