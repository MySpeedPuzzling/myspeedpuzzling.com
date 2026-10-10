<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ContentDigestRun;
use SpeedPuzzling\Web\Message\PlanContentDigestRuns;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\Query\GetContentDigestRecipients;
use SpeedPuzzling\Web\Repository\ContentDigestRunRepository;
use SpeedPuzzling\Web\Results\ContentDigestRunPlan;
use SpeedPuzzling\Web\Services\Digest\DigestPacing;
use SpeedPuzzling\Web\Services\Digest\MissYouHoldout;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\MissYouRun;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Queues today's activity digests (Monday) for every frequency whose period is due, or today's "We miss you" e-mails
 * (Tuesday) for every frequency (docs/features/content-digest/README.md §"Sending"). Activity, per frequency: the
 * latest period whose send day has come (DigestPeriod::latestSentBy()), skipped while it starts before the first digest
 * period (the launch pause - a guard in code, not only a cron date) or once it is stale. "We miss you": not before
 * MissYouRun::FIRST_DAY; optionally sliced by the last result's moment; the holdout share (MissYouHoldout) is queued
 * without delay and only logged. `maxRecipients` caps the planning across frequencies (holdout included).
 *
 * Nothing at all while the XP launch is not ready (XpFeatureGate::isEmailSendingEnabled(), the one launch switch).
 *
 * One ContentDigestRun per frequency, its recipients queued most engaged first and spaced over the day
 * (DigestPacing). One transaction for the whole planning (the Doctrine queue included): a failure queues nothing
 * and a re-run starts over; a re-run after success queues nobody twice - recipients exclude everybody with an
 * e-mail about the period, and a queued message re-checks that before sending.
 */
#[AsMessageHandler]
readonly final class PlanContentDigestRunsHandler
{
    public function __construct(
        private GetContentDigestRecipients $getContentDigestRecipients,
        private ContentDigestRunRepository $runRepository,
        private DigestPacing $pacing,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private XpFeatureGate $xpFeatureGate,
        private MissYouHoldout $missYouHoldout,
    ) {
    }

    /**
     * @return list<ContentDigestRunPlan>
     */
    public function __invoke(PlanContentDigestRuns $message): array
    {
        $now = $this->clock->now();
        $plans = [];
        $recipientsLeft = $message->maxRecipients;

        foreach (ContentDigestFrequency::sending() as $frequency) {
            if ($message->kind === ContentDigestKind::Activity) {
                $length = $frequency->periodLength();
                assert($length !== null);
                $period = DigestPeriod::latestSentBy($length, $now);
                $key = $period->key;

                $skipped = match (true) {
                    // The launch switch: nothing is sent before the XP launch is ready (XpFeatureGate)
                    $this->xpFeatureGate->isEmailSendingEnabled() === false => 'not_ready',
                    $period->isBeforeFirstPeriod() => 'paused',
                    $period->isStaleAt($now) => 'stale',
                    default => null,
                };
            } else {
                $period = null;
                $key = MissYouRun::runKey($now);

                $skipped = match (true) {
                    $this->xpFeatureGate->isEmailSendingEnabled() === false => 'not_ready',
                    MissYouRun::isAllowedOn($now) === false => 'paused',
                    default => null,
                };
            }

            if ($skipped !== null) {
                $plans[] = new ContentDigestRunPlan($frequency, $key, $skipped);

                continue;
            }

            $lastLogged = $period === null
                ? $this->getContentDigestRecipients->missedWithLastLogged($frequency, $now, $message->lastActivityAfter, $message->lastActivityBefore)
                : [];
            $recipients = $period !== null
                ? $this->getContentDigestRecipients->withActivity($frequency, $period)
                : array_keys($lastLogged);

            if ($recipientsLeft !== null) {
                $recipients = array_slice($recipients, 0, max(0, $recipientsLeft));
                $recipientsLeft -= count($recipients);
            }

            // "We miss you": the holdout is not mailed - its messages only log the holdout, so they take no slot
            // of the day's pacing
            $heldOut = $period === null
                ? array_values(array_filter($recipients, $this->missYouHoldout->isHeldOut(...)))
                : [];
            $mailed = array_values(array_diff($recipients, $heldOut));
            $pacing = $this->pacing->plan(count($mailed), $now);
            $buckets = $period === null ? self::recencyBuckets(array_intersect_key($lastLogged, array_flip($recipients)), $now) : [];

            if ($message->dryRun) {
                $plans[] = new ContentDigestRunPlan($frequency, $key, 'dry_run', count($recipients), $pacing['spacingMs'], $buckets, count($heldOut));

                continue;
            }

            if ($recipients === []) {
                $plans[] = new ContentDigestRunPlan($frequency, $key, 'planned');

                continue;
            }

            $runId = Uuid::uuid7();
            $this->runRepository->save(new ContentDigestRun(
                id: $runId,
                kind: $message->kind,
                frequency: $frequency,
                periodKey: $key,
                recipients: count($recipients),
                startedAt: $now,
                spacingSeconds: intdiv($pacing['spacingMs'], 1000),
            ));

            foreach ($mailed as $index => $playerId) {
                $this->messageBus->dispatch(
                    new SendPlayerContentDigest($playerId, $key, $message->kind, $runId->toString()),
                    [new DelayStamp($pacing['firstDelayMs'] + $index * $pacing['spacingMs'])],
                );
            }

            foreach ($heldOut as $playerId) {
                $this->messageBus->dispatch(new SendPlayerContentDigest($playerId, $key, $message->kind, $runId->toString()));
            }

            $plans[] = new ContentDigestRunPlan($frequency, $key, 'planned', count($recipients), $pacing['spacingMs'], $buckets, count($heldOut));
        }

        return $plans;
    }

    /**
     * How long ago the last result was - the slices of the first wave (README §"We miss you").
     *
     * @param array<string, DateTimeImmutable> $lastLogged
     * @return array<string, int>
     */
    private static function recencyBuckets(array $lastLogged, DateTimeImmutable $now): array
    {
        $buckets = ['28d-3m' => 0, '3-9m' => 0, 'older-visited' => 0];

        foreach ($lastLogged as $at) {
            // Older than the dormancy limit and still eligible = visited the site recently
            $bucket = match (true) {
                $at > $now->modify('-3 months') => '28d-3m',
                $at > $now->modify(sprintf('-%d months', GetContentDigestRecipients::MISS_YOU_DORMANT_AFTER_MONTHS)) => '3-9m',
                default => 'older-visited',
            };
            $buckets[$bucket]++;
        }

        return $buckets;
    }
}
