<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;

/**
 * Whether a finish moment is one the participant's phone recorded (docs/features/online-competitions/README.md §1 P2:
 * "No official time is typed by a person - the review only chooses between recorded moments"). By the confirmation's
 * source:
 * - button: a Finished tap (`finish.button`) at exactly that millisecond;
 * - detection: a finish candidate (`finish.candidate`, Phase 2) at exactly that millisecond;
 * - reviewer: either of those, or a frame of one of the entry's finish bursts - its capture moment rounded to the
 *   millisecond, as the review's scrubber sends it (the manifest keeps the frame's exact time).
 * One statement.
 */
readonly final class GetOnlineEntryRecordedMoments
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function isRecorded(string $onlineEntryId, OnlineConfirmedSource $source, int $momentMs): bool
    {
        if (Uuid::isValid($onlineEntryId) === false) {
            return false;
        }

        $parameters = [
            'onlineEntryId' => strtolower($onlineEntryId),
            'eventTypes' => match ($source) {
                OnlineConfirmedSource::Button => [OnlineEventType::FinishButton->value],
                OnlineConfirmedSource::Detection => [OnlineEventType::FinishCandidate->value],
                OnlineConfirmedSource::Reviewer => [OnlineEventType::FinishButton->value, OnlineEventType::FinishCandidate->value],
            },
            'momentMs' => $momentMs,
        ];
        $burstFrame = '';

        if ($source === OnlineConfirmedSource::Reviewer) {
            $parameters['burst'] = OnlineMediaKind::Burst->value;
            $burstFrame = <<<SQL
    OR EXISTS (
        SELECT 1
        FROM online_entry_media m
        CROSS JOIN LATERAL jsonb_array_elements(
            CASE WHEN jsonb_typeof(m.manifest) = 'array' THEN m.manifest ELSE '[]'::jsonb END
        ) AS frame
        WHERE m.online_entry_id = :onlineEntryId
            AND m.kind = :burst
            AND jsonb_typeof(frame->'captureMs') = 'number'
            AND round((frame->>'captureMs')::numeric) = :momentMs
    )
SQL;
        }

        $recorded = $this->database->fetchOne(
            <<<SQL
SELECT EXISTS (
        SELECT 1
        FROM online_entry_event e
        WHERE e.online_entry_id = :onlineEntryId
            AND e.type IN (:eventTypes)
            AND e.client_ms = :momentMs
    ){$burstFrame}
SQL,
            $parameters,
            [
                'eventTypes' => ArrayParameterType::STRING,
                'momentMs' => ParameterType::INTEGER,
            ],
        );

        // pdo_pgsql answers a boolean as one - the others are the drivers that answer text or a number
        return in_array($recorded, [true, 't', '1', 1], true);
    }
}
