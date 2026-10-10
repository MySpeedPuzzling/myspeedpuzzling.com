<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ContentDigestRun;
use SpeedPuzzling\Web\Message\PlanContentDigestRuns;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\Query\GetContentDigestRecipients;
use SpeedPuzzling\Web\Repository\ContentDigestRunRepository;
use SpeedPuzzling\Web\Results\ContentDigestRunPlan;
use SpeedPuzzling\Web\Services\Digest\DigestPacing;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Queues today's activity digests (Monday) or no-activity e-mails (the day after) for every frequency whose period
 * is due (docs/features/content-digest/README.md §"Sending"). Per frequency: the latest period whose send day has
 * come (DigestPeriod::latestSentBy()), skipped while it starts before the first digest period (the launch pause - a
 * guard in code, not only a cron date), once it is stale, or - no-activity - before the day after its send day.
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
    ) {
    }

    /**
     * @return list<ContentDigestRunPlan>
     */
    public function __invoke(PlanContentDigestRuns $message): array
    {
        $now = $this->clock->now();
        $plans = [];

        foreach (ContentDigestFrequency::sending() as $frequency) {
            $length = $frequency->periodLength();
            assert($length !== null);
            $period = DigestPeriod::latestSentBy($length, $now);

            $skipped = match (true) {
                // The launch switch: nothing is sent before the XP launch is ready (XpFeatureGate)
                $this->xpFeatureGate->isEmailSendingEnabled() === false => 'not_ready',
                $period->isBeforeFirstPeriod() => 'paused',
                $period->isStaleAt($now) => 'stale',
                $message->kind === ContentDigestKind::NoActivity && $now < $period->noActivityDay() => 'not_yet',
                default => null,
            };

            if ($skipped !== null) {
                $plans[] = new ContentDigestRunPlan($frequency, $period->key, $skipped);

                continue;
            }

            $recipients = $this->getContentDigestRecipients->of($message->kind, $frequency, $period);
            $pacing = $this->pacing->plan(count($recipients), $now);

            if ($message->dryRun) {
                $plans[] = new ContentDigestRunPlan($frequency, $period->key, 'dry_run', count($recipients), $pacing['spacingMs']);

                continue;
            }

            if ($recipients === []) {
                $plans[] = new ContentDigestRunPlan($frequency, $period->key, 'planned');

                continue;
            }

            $runId = Uuid::uuid7();
            $this->runRepository->save(new ContentDigestRun(
                id: $runId,
                kind: $message->kind,
                frequency: $frequency,
                periodKey: $period->key,
                recipients: count($recipients),
                startedAt: $now,
                spacingSeconds: intdiv($pacing['spacingMs'], 1000),
            ));

            foreach ($recipients as $index => $playerId) {
                $this->messageBus->dispatch(
                    new SendPlayerContentDigest($playerId, $period->key, $message->kind, $runId->toString()),
                    [new DelayStamp($pacing['firstDelayMs'] + $index * $pacing['spacingMs'])],
                );
            }

            $plans[] = new ContentDigestRunPlan($frequency, $period->key, 'planned', count($recipients), $pacing['spacingMs']);
        }

        return $plans;
    }
}
