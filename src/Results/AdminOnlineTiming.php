<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use SpeedPuzzling\Web\Entity\OnlineRound;

/**
 * A round's online timing as the internal API shows it (docs/features/internal-api.md "Online timing") - the settings
 * the round's "Online timing" page edits (OnlineRoundSettingsFormData) plus the two the page keeps as they are (studio,
 * recording). `GET /internal-api/competitions/{idOrSlug}` carries it per round (`onlineTiming`, null = the round does
 * not use online timing), `PUT /internal-api/rounds/{roundId}/online-timing` answers it.
 */
readonly final class AdminOnlineTiming
{
    public function __construct(
        // In UTC (`2026-10-06T08:00:00+00:00`)
        public string $enabledAt,
        public string $detectionMode,
        public string $unfinishedMeasure,
        public string $missingPieceRule,
        public bool $videoEnabled,
        public bool $participantsSeeEachOther,
        public bool $studioEnabled,
        public bool $recordingEnabled,
    ) {
    }

    public static function fromOnlineRound(OnlineRound $onlineRound): self
    {
        return new self(
            enabledAt: $onlineRound->enabledAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            detectionMode: $onlineRound->detectionMode->value,
            unfinishedMeasure: $onlineRound->unfinishedMeasure->value,
            missingPieceRule: $onlineRound->missingPieceRule->value,
            videoEnabled: $onlineRound->videoEnabled,
            participantsSeeEachOther: $onlineRound->participantsSeeEachOther,
            studioEnabled: $onlineRound->studioEnabled,
            recordingEnabled: $onlineRound->recordingEnabled,
        );
    }

    /**
     * The `online_*` columns of GetAdminCompetitions' round statement (LEFT JOIN online_round) - null when the round
     * has no online timing.
     *
     * @param array{
     *     online_enabled_at?: null|string,
     *     online_detection_mode?: null|string,
     *     online_unfinished_measure?: null|string,
     *     online_missing_piece_rule?: null|string,
     *     online_video_enabled?: null|bool,
     *     online_participants_see_each_other?: null|bool,
     *     online_studio_enabled?: null|bool,
     *     online_recording_enabled?: null|bool,
     *     ...
     * } $row
     */
    public static function fromDatabaseRow(array $row): null|self
    {
        $enabledAt = $row['online_enabled_at'] ?? null;

        if ($enabledAt === null) {
            return null;
        }

        return new self(
            enabledAt: new DateTimeImmutable($enabledAt, new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            detectionMode: (string) ($row['online_detection_mode'] ?? ''),
            unfinishedMeasure: (string) ($row['online_unfinished_measure'] ?? ''),
            missingPieceRule: (string) ($row['online_missing_piece_rule'] ?? ''),
            videoEnabled: ($row['online_video_enabled'] ?? false) === true,
            participantsSeeEachOther: ($row['online_participants_see_each_other'] ?? false) === true,
            studioEnabled: ($row['online_studio_enabled'] ?? false) === true,
            recordingEnabled: ($row['online_recording_enabled'] ?? false) === true,
        );
    }

    /**
     * @return array{enabledAt: string, detectionMode: string, unfinishedMeasure: string, missingPieceRule: string, videoEnabled: bool, participantsSeeEachOther: bool, studioEnabled: bool, recordingEnabled: bool}
     */
    public function toArray(): array
    {
        return [
            'enabledAt' => $this->enabledAt,
            'detectionMode' => $this->detectionMode,
            'unfinishedMeasure' => $this->unfinishedMeasure,
            'missingPieceRule' => $this->missingPieceRule,
            'videoEnabled' => $this->videoEnabled,
            'participantsSeeEachOther' => $this->participantsSeeEachOther,
            'studioEnabled' => $this->studioEnabled,
            'recordingEnabled' => $this->recordingEnabled,
        ];
    }
}
