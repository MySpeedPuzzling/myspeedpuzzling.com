<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use LogicException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;
use SpeedPuzzling\Web\Message\CheckOnlineFinishPhoto;
use SpeedPuzzling\Web\Message\RecordOnlineMediaCheck;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Repository\OnlineMediaCheckRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvClient;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvInputs;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvSettings;
use SpeedPuzzling\Web\Value\OnlineCvAnswer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The automated completeness check of one finish photo (docs/features/online-competitions/README.md §5.8, Phase 3 -
 * advisory: the review shows the verdict and orders its queue by it; nothing else reads it). Async: the CV worker
 * (tools/online-cv/) downloads the photo, the entry's empty mat and the round puzzle's catalogue image by presigned URLs
 * (OnlineCvInputs) and answers a verdict (OnlineCvClient) - stored as the photo's OnlineMediaCheck for the worker's
 * version by RecordOnlineMediaCheck.
 * - Outside any transaction (CheckOnlineFinishPhoto is HandledOutsideTransaction): the worker takes up to
 *   OnlineCvClient::TIMEOUT_SECONDS - this handler only reads and asks; the answer is written by RecordOnlineMediaCheck
 *   (handled at once, its own transaction, under the photo's lock - a duplicate answer finds the row there).
 * - Only while the check is on (ONLINE_CV_URL), only for a finish photo (`candidate`, `button`, `end`) the server
 *   verified; a photo with a verdict already is left alone unless forced (the same version then replaces its row, a newer
 *   one adds its own).
 * - A busy (503) or unreachable worker, or a store it could not reach (502): asked again later by a message of its own
 *   (RETRY_DELAYS_MS) - never by failing this one: the transport's retries would bring every failure to the logs and
 *   Sentry. After MAX_ATTEMPTS, or at once when the worker refused the photo for good, the check is recorded as
 *   `failed` (the row of OnlineMediaCheck::NO_VERSION, with the reason) - quietly: a warning for the first failure of a
 *   round only, the rest at info - a person looks at the photo as they would without the check.
 */
#[AsMessageHandler]
readonly final class CheckOnlineFinishPhotoHandler
{
    public const int MAX_ATTEMPTS = 4;
    // The wait before the 2nd, 3rd and 4th try - a worker busy with a round's finishes, a store down for minutes
    public const array RETRY_DELAYS_MS = [30_000, 120_000, 480_000];

    public function __construct(
        private OnlineCvSettings $settings,
        private OnlineEntryMediaRepository $onlineEntryMediaRepository,
        private OnlineMediaCheckRepository $onlineMediaCheckRepository,
        private OnlineCvInputs $inputs,
        private OnlineCvClient $client,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckOnlineFinishPhoto $message): void
    {
        if ($this->settings->isOn() === false) {
            return;
        }

        try {
            $media = $this->onlineEntryMediaRepository->get($message->mediaId);
        } catch (OnlineEntryMediaNotFound) {
            // Pruned, or its participant removed meanwhile
            return;
        }

        if ($media->kind->isFinishPhoto() === false || $media->verifiedBytes === null) {
            return;
        }

        if ($message->force === false && $this->onlineMediaCheckRepository->hasVerdict($message->mediaId)) {
            return;
        }

        try {
            $request = $this->inputs->for($media);
        } catch (LogicException $e) {
            // A store no longer configured (the second one removed - OnlineMediaStores::serverLocation()), an endpoint
            // that is no URL - nothing the worker could read. Anything else (the database) fails the message as ever.
            $this->recordFailure($media, 'inputs_unavailable', $message, $e);

            return;
        }

        $answer = $this->client->check($request);

        if ($answer->kind === OnlineCvAnswer::VERDICT) {
            $this->messageBus->dispatch(new RecordOnlineMediaCheck($message->mediaId, $answer, $message->attempt, $message->force));
            $this->logger->info('An online finish photo was checked for completeness', [
                'media_id' => $message->mediaId,
                'verdict' => $answer->verdict?->value,
                'method' => $answer->method,
                'ms' => $answer->ms,
            ]);

            return;
        }

        if ($answer->kind === OnlineCvAnswer::RETRY && $message->attempt < self::MAX_ATTEMPTS) {
            $this->messageBus->dispatch(
                new CheckOnlineFinishPhoto($message->mediaId, $message->attempt + 1, $message->force),
                [new DelayStamp(self::RETRY_DELAYS_MS[$message->attempt - 1] ?? self::RETRY_DELAYS_MS[count(self::RETRY_DELAYS_MS) - 1])],
            );
            $this->logger->info('The completeness check of an online finish photo is asked again later', [
                'media_id' => $message->mediaId,
                'attempt' => $message->attempt,
                'error' => $answer->error,
            ]);

            return;
        }

        $this->recordFailure($media, $answer->error ?? 'failed', $message);
    }

    private function recordFailure(OnlineEntryMedia $media, string $error, CheckOnlineFinishPhoto $message, null|LogicException $exception = null): void
    {
        // Said once per round: the first failure is for a person to look at, the rest are the same story
        $firstOfRound = $this->onlineMediaCheckRepository->roundHasFailure($media) === false;

        $this->messageBus->dispatch(new RecordOnlineMediaCheck($message->mediaId, OnlineCvAnswer::failed($error), $message->attempt));

        $context = [
            'media_id' => $message->mediaId,
            'round_id' => $media->onlineEntry->entry->round->id->toString(),
            'error' => $error,
            'attempts' => $message->attempt,
        ];

        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        $this->logger->log(
            $firstOfRound ? 'warning' : 'info',
            'The completeness check of an online finish photo could not run - recorded as failed (said once per round)',
            $context,
        );
    }
}
