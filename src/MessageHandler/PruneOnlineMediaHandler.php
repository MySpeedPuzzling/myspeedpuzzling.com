<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Message\PruneOnlineMedia;
use SpeedPuzzling\Web\Results\PrunedOnlineMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Online competition evidence past its retention (docs/features/online-competitions/README.md §8, §13.6): periodic
 * photos after `olderThanDays` (the cron passes 30), everything else - start, finish and end photos, bursts, the empty
 * mat - after EVIDENCE_RETENTION_DAYS (12 months: disputes, records), counted from when the row was made. Each object
 * is deleted from its store, then the rows.
 *
 * One batch per message (BATCH_SIZE, oldest first) - the command asks again while `hasMore`, so a big round's photos
 * never hold one transaction open across thousands of storage requests. A store failing stops the batch with nothing
 * of it deleted from the database; the next run deletes those objects again (a missing key is no error).
 */
#[AsMessageHandler]
readonly final class PruneOnlineMediaHandler
{
    public const int EVIDENCE_RETENTION_DAYS = 365;
    public const int BATCH_SIZE = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OnlineMediaStorage $onlineMediaStorage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneOnlineMedia $message): PrunedOnlineMedia
    {
        $now = $this->clock->now();
        $periodicBefore = $now->modify(sprintf('-%d days', max(1, $message->olderThanDays)));
        $evidenceBefore = $now->modify(sprintf('-%d days', max(self::EVIDENCE_RETENTION_DAYS, $message->olderThanDays)));

        /** @var list<OnlineEntryMedia> $media */
        $media = $this->entityManager->createQueryBuilder()
            ->select('media')
            ->from(OnlineEntryMedia::class, 'media')
            ->where('(media.kind = :periodic AND media.createdAt < :periodicBefore) OR (media.kind <> :periodic AND media.createdAt < :evidenceBefore)')
            ->setParameter('periodic', OnlineMediaKind::Periodic)
            ->setParameter('periodicBefore', $periodicBefore)
            ->setParameter('evidenceBefore', $evidenceBefore)
            ->orderBy('media.createdAt', 'ASC')
            ->addOrderBy('media.id', 'ASC')
            // One more tells whether another batch follows
            ->setMaxResults(self::BATCH_SIZE + 1)
            ->getQuery()
            ->getResult();

        $hasMore = count($media) > self::BATCH_SIZE;
        $media = array_slice($media, 0, self::BATCH_SIZE);

        $periodic = 0;
        $evidence = 0;

        foreach ($media as $item) {
            $this->onlineMediaStorage->delete($item);

            if ($item->kind === OnlineMediaKind::Periodic) {
                $periodic++;
            } else {
                $evidence++;
            }
        }

        if ($media !== []) {
            // The prune exception to "no bulk SQL" (CLAUDE.md, State-Changing Operations Pattern): a cron deleting up to
            // a whole batch of rows that nothing else reads any more - one statement instead of a DELETE per row
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM online_entry_media WHERE id IN (:ids)',
                ['ids' => array_map(static fn (OnlineEntryMedia $item): string => $item->id->toString(), $media)],
                ['ids' => ArrayParameterType::STRING],
            );

            // Deleted behind the unit of work's back - never written again
            foreach ($media as $item) {
                $this->entityManager->detach($item);
            }
        }

        return new PrunedOnlineMedia(
            periodic: $periodic,
            evidence: $evidence,
            hasMore: $hasMore,
        );
    }
}
