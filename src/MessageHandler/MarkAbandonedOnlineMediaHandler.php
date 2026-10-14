<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Doctrine\UuidType;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Message\MarkAbandonedOnlineMedia;
use SpeedPuzzling\Web\Results\MarkedAbandonedOnlineMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Evidence uploads that never arrived (docs/features/online-competitions/README.md §5.11): a row the phone never reported
 * uploaded, ABANDONED_AFTER_SECONDS after it was made - its presigned URL ran out long before (5 minutes,
 * S3OnlineMediaStorage::PUT_LIFETIME_SECONDS, plus the slowest upload) - is asked for in its store: nothing there →
 * marked abandoned (OnlineEntryMedia::markAbandoned()), the review says "never arrived" instead of "pending". Covers
 * what the presign path cannot know: a URL that simply ran out (the phone was offline), a periodic photo the phone
 * thinned, a phone that never came back. An object that is there stays as it is: its report (`media.uploaded`) may
 * still come from a phone that was offline - and if the phone reports one already marked, the upload wins.
 *
 * Like PruneOnlineMediaHandler: one page per message, oldest first by (created_at, id), at most PAGE_SIZE storage
 * requests inside its transaction; a store failing MAX_FAILURES_IN_A_ROW times in a row is down - the page stops
 * (`storeFailing`); a row of the second store while none is configured is skipped.
 */
#[AsMessageHandler]
readonly final class MarkAbandonedOnlineMediaHandler
{
    public const int ABANDONED_AFTER_SECONDS = 1_800;
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

    public function __invoke(MarkAbandonedOnlineMedia $message): MarkedAbandonedOnlineMedia
    {
        $now = $this->clock->now();

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('media')
            ->from(OnlineEntryMedia::class, 'media')
            ->where('media.uploadedAtMs IS NULL')
            ->andWhere('media.abandonedAt IS NULL')
            ->andWhere('media.createdAt < :before')
            ->setParameter('before', $now->modify(sprintf('-%d seconds', self::ABANDONED_AFTER_SECONDS)))
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

        $abandoned = 0;
        $inStore = 0;
        $failed = 0;
        $skipped = 0;
        $failuresInARow = 0;
        $storeFailing = false;

        foreach ($page as $media) {
            if ($media->store === OnlineMediaStore::Secondary && $this->onlineMediaStores->hasSecondary() === false) {
                $skipped++;

                continue;
            }

            try {
                $bytes = $this->onlineMediaStorage->head($media);
            } catch (\Throwable $e) {
                $failed++;
                $failuresInARow++;
                $this->logger->warning('Asking the store for an online upload that never arrived failed - the next run asks again', [
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

            if ($bytes !== null) {
                $inStore++;

                continue;
            }

            if ($media->markAbandoned($now)) {
                $abandoned++;
            }
        }

        $last = $page === [] ? null : $page[count($page) - 1];
        $full = count($page) === self::PAGE_SIZE;

        return new MarkedAbandonedOnlineMedia(
            abandoned: $abandoned,
            inStore: $inStore,
            failed: $failed,
            skipped: $skipped,
            nextAfterCreatedAt: $full ? $last?->createdAt : null,
            nextAfterId: $full ? $last?->id->toString() : null,
            storeFailing: $storeFailing,
        );
    }
}
