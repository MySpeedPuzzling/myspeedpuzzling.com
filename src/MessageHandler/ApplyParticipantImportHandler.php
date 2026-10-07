<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ParticipantImportNotApplicable;
use SpeedPuzzling\Web\Exceptions\ParticipantImportPreviewStale;
use SpeedPuzzling\Web\Message\ApplyParticipantImport;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportApplier;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Plans again - inside the transaction the middleware opened, under the event's lock (SerializedByLock) - and
 * applies only the plan the organiser saw: same rows, same mode, same event state and, for full sync, the same
 * removals kept because of results (D8, D11). Everything is checked before anything is changed. Never touches the
 * stashed file - the controller marks it applied after a successful dispatch.
 */
#[AsMessageHandler]
readonly final class ApplyParticipantImportHandler
{
    public function __construct(
        private ParticipantImportPlanner $planner,
        private ParticipantImportApplier $applier,
    ) {
    }

    /**
     * @throws ParticipantImportPreviewStale
     * @throws ParticipantImportNotApplicable
     */
    public function __invoke(ApplyParticipantImport $message): ParticipantImportResult
    {
        $mode = ParticipantImportMode::tryFrom($message->mode) ?? throw new ParticipantImportNotApplicable();

        $plan = $this->planner->plan($message->competitionId, $message->rows, $mode);

        if (!hash_equals($plan->fingerprint, $message->expectedFingerprint)) {
            throw new ParticipantImportPreviewStale();
        }

        if (!$plan->canBeApplied()) {
            throw new ParticipantImportNotApplicable();
        }

        return $this->applier->apply($message->competitionId, $plan);
    }
}
