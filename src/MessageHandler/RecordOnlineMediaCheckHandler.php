<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineMediaCheck;
use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;
use SpeedPuzzling\Web\Message\RecordOnlineMediaCheck;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Repository\OnlineMediaCheckRepository;
use SpeedPuzzling\Web\Value\OnlineCvAnswer;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The CV worker's answer for one finish photo as its OnlineMediaCheck (docs/features/online-competitions/README.md
 * §5.8, Phase 3) - under the photo's lock (RecordOnlineMediaCheck), so "is there a row of this version yet?" and the
 * insert are never interleaved with another answer for the same photo:
 * - a verdict: a new row for its worker version; a version that has its row already keeps it (a duplicate answer -
 *   the photo is checked) unless the check was forced, then the answer replaces it;
 * - a check that could not run: the row of OnlineMediaCheck::NO_VERSION, created or updated with the latest reason - a
 *   verdict a version gave before stays.
 * A photo deleted meanwhile (pruned, its participant removed) has nothing to record.
 */
#[AsMessageHandler]
readonly final class RecordOnlineMediaCheckHandler
{
    public function __construct(
        private OnlineEntryMediaRepository $onlineEntryMediaRepository,
        private OnlineMediaCheckRepository $onlineMediaCheckRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordOnlineMediaCheck $message): void
    {
        try {
            $media = $this->onlineEntryMediaRepository->get($message->mediaId);
        } catch (OnlineEntryMediaNotFound) {
            return;
        }

        $answer = $message->answer;
        $now = $this->clock->now();

        if ($answer->kind === OnlineCvAnswer::VERDICT && $answer->verdict !== null && $answer->version !== null) {
            $existing = $this->onlineMediaCheckRepository->ofMedia($message->mediaId, $answer->version);

            if ($existing === null) {
                $this->onlineMediaCheckRepository->save(new OnlineMediaCheck(
                    id: Uuid::uuid7(),
                    media: $media,
                    workerVersion: $answer->version,
                    verdict: $answer->verdict,
                    checkedAt: $now,
                    confidence: $answer->confidence,
                    gaps: $answer->gaps,
                    reason: $answer->reason,
                    method: $answer->method,
                    ms: $answer->ms,
                    imageWidth: $answer->imageWidth,
                    imageHeight: $answer->imageHeight,
                    details: $answer->details,
                    attempts: $message->attempts,
                ));

                return;
            }

            if ($message->force) {
                $existing->checkedAgain(
                    verdict: $answer->verdict,
                    checkedAt: $now,
                    confidence: $answer->confidence,
                    gaps: $answer->gaps,
                    reason: $answer->reason,
                    method: $answer->method,
                    ms: $answer->ms,
                    imageWidth: $answer->imageWidth,
                    imageHeight: $answer->imageHeight,
                    details: $answer->details,
                    attempts: $message->attempts,
                );
            }

            return;
        }

        $error = mb_substr($answer->error ?? 'failed', 0, 255);
        $existing = $this->onlineMediaCheckRepository->ofMedia($message->mediaId, OnlineMediaCheck::NO_VERSION);

        if ($existing !== null) {
            $existing->failedAgain($error, $now, $message->attempts);

            return;
        }

        $this->onlineMediaCheckRepository->save(new OnlineMediaCheck(
            id: Uuid::uuid7(),
            media: $media,
            workerVersion: OnlineMediaCheck::NO_VERSION,
            verdict: OnlineFinishCheckVerdict::Failed,
            checkedAt: $now,
            error: $error,
            attempts: $message->attempts,
        ));
    }
}
