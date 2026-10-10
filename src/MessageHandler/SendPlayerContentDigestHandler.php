<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\ContentDigestLog;
use SpeedPuzzling\Web\Exceptions\InvalidDigestPeriod;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\Query\GetContentDigestRecipients;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\ContentDigestLogRepository;
use SpeedPuzzling\Web\Repository\ContentDigestRunRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\ContentDigestRunCounts;
use SpeedPuzzling\Web\Services\ContentDigestUnsubscribeUrl;
use SpeedPuzzling\Web\Services\Digest\PlayerPeriodActivityReader;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriodLength;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends ONE activity digest or no-activity e-mail to ONE player (docs/features/content-digest/README.md §"Sending").
 * SMTP happens here through TransportInterface::send() (README D1), so pacing, retries and the circuit breaker live
 * exactly where the sending does, away from the transactional mail on the async queue.
 *
 * 1. Dropped without sending: anything while the XP launch is not ready (XpFeatureGate), a period before the first digest period (the launch pause, checked here too), a stale
 *    one, a stopped run, a player who is gone / has no e-mail / switched the digest off or to another frequency / is
 *    no longer a recipient of this kind (GetContentDigestRecipients::isRecipient() - results change in the hours
 *    between planning and sending).
 * 2. Claimed (ContentDigestLogRepository::claim()) - one e-mail per player and period, race-safe.
 * 3. Sent. Outcomes, counted on the run: sent; permanent recipient failure (550-553) = row `failed_permanent`, never
 *    retried; anything else the transport throws (connection, 4xx, sender-side 5xx) = row `deferred`, retried by us
 *    with a growing delay up to MAX_ATTEMPTS (a later run may take it over). Neither failure throws, so the outcome
 *    commits (the doctrine_transaction middleware rolls back on any throw) - other exceptions (a template error,
 *    the database) still bubble to Messenger's retry and leave nothing behind.
 * 4. Circuit breaker: once a run has BREAKER_MIN_FAILURES failures and they are BREAKER_FAILURE_SHARE of its
 *    attempts, the run is stopped - one warning, every message of it still queued is dropped. A later run of the
 *    same period (re-run of the command) sends to whoever got nothing.
 */
#[AsMessageHandler]
readonly final class SendPlayerContentDigestHandler
{
    public const int BREAKER_MIN_FAILURES = 20;
    public const float BREAKER_FAILURE_SHARE = 0.05;
    public const int MAX_ATTEMPTS = 3;
    private const int RETRY_DELAY_MS = 600_000;

    public function __construct(
        private PlayerRepository $playerRepository,
        private GetPlayerProfile $getPlayerProfile,
        private GetContentDigestRecipients $getContentDigestRecipients,
        private ContentDigestLogRepository $logRepository,
        private ContentDigestRunRepository $runRepository,
        private PlayerPeriodActivityReader $activityReader,
        private TransportInterface $transport,
        private TranslatorInterface $translator,
        private ContentDigestUnsubscribeUrl $unsubscribeUrl,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private PlayerAccountEmail $playerAccountEmail,
        private XpFeatureGate $xpFeatureGate,
    ) {
    }

    public function __invoke(SendPlayerContentDigest $message): void
    {
        $now = $this->clock->now();

        try {
            $period = DigestPeriod::fromKey($message->periodKey);
        } catch (InvalidDigestPeriod) {
            return;
        }

        // The launch switch (XpFeatureGate), the launch pause (a period before the first one) and staleness
        if ($this->xpFeatureGate->isEmailSendingEnabled() === false || $period->isBeforeFirstPeriod() || $period->isStaleAt($now)) {
            $this->logger->info('Skipping a digest outside its sending days', ['playerId' => $message->playerId, 'period' => $period->key]);

            return;
        }

        if ($this->runRepository->isStopped($message->runId)) {
            return;
        }

        try {
            $player = $this->playerRepository->get($message->playerId);
        } catch (PlayerNotFound) {
            return;
        }

        $email = $this->playerAccountEmail->ofPlayer($player);
        $frequency = ContentDigestFrequency::forPeriodLength($period->length);

        if (
            $email === null
            || $player->contentDigestFrequency !== $frequency
            || $this->getContentDigestRecipients->isRecipient($message->playerId, $message->kind, $frequency, $period) === false
        ) {
            return;
        }

        $hadActivity = $message->kind === ContentDigestKind::Activity;

        if ($this->logRepository->claim($message->playerId, $frequency, $period->key, $hadActivity, $now) === false) {
            return;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($player->locale);
        $profile = $this->getPlayerProfile->byId($message->playerId);
        $activity = $this->activityReader->read($message->playerId, $period, $profile->activeMembership);
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($message->playerId, $locale);

        $templatedEmail = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($email)
            ->locale($locale)
            ->subject($this->subject($message->kind, $period->length, $locale))
            ->htmlTemplate($hadActivity ? 'emails/content_digest_activity.html.twig' : 'emails/content_digest_no_activity.html.twig')
            ->context([
                'playerName' => $player->name,
                'activity' => $activity,
                'period' => $period,
                'frequency' => $frequency,
                'unsubscribeUrl' => $unsubscribeUrl,
                'preferencesUrl' => $this->emailPreferencesLinkGenerator->forPlayer($message->playerId, $email, $locale),
                'locale' => $locale,
            ]);

        $headers = $templatedEmail->getHeaders();
        $headers->addTextHeader('X-Transport', 'notifications');
        $headers->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->addTextHeader('Precedence', 'bulk');

        try {
            $this->transport->send($templatedEmail);
        } catch (TransportExceptionInterface $exception) {
            $this->failed($message, $period, $exception, $now);

            return;
        }

        $this->runRepository->count($message->runId, 'sent');
    }

    private function failed(SendPlayerContentDigest $message, DigestPeriod $period, TransportExceptionInterface $exception, \DateTimeImmutable $now): void
    {
        // 550-553 are unambiguously recipient-stage (mailbox unknown/full/denied). 554 also fires at MAIL FROM (relay
        // denied) and 535 at auth - sender-side, so they count as "try later" and feed the breaker
        $permanent = $exception instanceof UnexpectedResponseException && in_array($exception->getCode(), [550, 551, 552, 553], true);

        $this->logRepository->markOutcome(
            $message->playerId,
            $period->key,
            $permanent ? ContentDigestLog::STATUS_FAILED_PERMANENT : ContentDigestLog::STATUS_DEFERRED,
        );

        // Info: one failed address is routine at digest volume - the breaker's warning is the signal to look at
        $this->logger->info($permanent ? 'Digest permanently rejected for recipient' : 'Digest deferred', [
            'playerId' => $message->playerId,
            'period' => $period->key,
            'attempt' => $message->attempt,
            'exception' => $exception,
        ]);

        $counts = $this->runRepository->count($message->runId, $permanent ? 'failed_permanently' : 'deferred');

        if ($this->breakerTrips($counts)) {
            if ($this->runRepository->stop($message->runId, $now)) {
                $this->logger->warning('Activity digest run stopped by the circuit breaker', [
                    'runId' => $message->runId,
                    'period' => $period->key,
                    'kind' => $message->kind->value,
                    'sent' => $counts->sent,
                    'failedPermanently' => $counts->failedPermanently,
                    'deferred' => $counts->deferred,
                    'exception' => $exception,
                ]);
            }

            return;
        }

        if ($permanent === false && $message->attempt + 1 < self::MAX_ATTEMPTS) {
            $this->messageBus->dispatch(
                new SendPlayerContentDigest($message->playerId, $message->periodKey, $message->kind, $message->runId, $message->attempt + 1),
                [new DelayStamp(self::RETRY_DELAY_MS * 3 ** $message->attempt)],
            );
        }
    }

    public static function breakerTrips(ContentDigestRunCounts $counts): bool
    {
        return $counts->failures() >= self::BREAKER_MIN_FAILURES
            && $counts->failures() >= self::BREAKER_FAILURE_SHARE * $counts->attempts();
    }

    private function subject(ContentDigestKind $kind, DigestPeriodLength $length, string $locale): string
    {
        $key = $kind === ContentDigestKind::Activity
            ? 'activity_digest.subject.' . $length->value
            : 'activity_digest.no_activity.subject';

        return $this->translator->trans($key, domain: 'emails', locale: $locale);
    }
}
