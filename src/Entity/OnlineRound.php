<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * A round that uses online timing (docs/features/online-competitions/README.md): participants compete at home on
 * their phones through personal links, the round's stopwatch is the one clock (RoundClock). One row per such round, its
 * settings; a round without a row is untouched by everything online. Changed only through changeSettings() and
 * changeYoutubeUrl().
 */
#[Entity]
class OnlineRound
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionRound $round,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $enabledAt,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $enabledBy,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 16, enumType: OnlineDetectionMode::class)]
        public OnlineDetectionMode $detectionMode = OnlineDetectionMode::Off,
        // Participants' puzzle cameras go to the media server (Phase 1B)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $videoEnabled = false,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $participantsSeeEachOther = false,
        // The host's studio with invited finishers (Phase 1B)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $studioEnabled = false,
        // The puzzle cameras recorded to object storage
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $recordingEnabled = false,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 16, enumType: OnlineUnfinishedMeasure::class)]
        public OnlineUnfinishedMeasure $unfinishedMeasure = OnlineUnfinishedMeasure::PiecesLeft,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 32, enumType: OnlineMissingPieceRule::class)]
        public OnlineMissingPieceRule $missingPieceRule = OnlineMissingPieceRule::CompleteRequired,
        // The organiser's own YouTube stream of the studio (docs/features/online-competitions/obs-youtube.md) - the
        // watch page offers it when the studio is full (Phase 1B)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 255, nullable: true)]
        public null|string $youtubeUrl = null,
    ) {
    }

    /**
     * The studio's YouTube stream - null or empty: none. Only a YouTube address over https is ever linked
     * (OnlineWatchController::youtubeLink()).
     */
    public function changeYoutubeUrl(null|string $youtubeUrl): void
    {
        $youtubeUrl = trim($youtubeUrl ?? '');

        $this->youtubeUrl = $youtubeUrl === '' ? null : $youtubeUrl;
    }

    /**
     * Finish detection is fixed from the round's start until a Reset (docs/features/online-competitions/README.md §5.7):
     * once a start is set - scheduled, counting down, running or over - the phones decided what they do with raised
     * hands, the review reads them by the same rule. Only a round waiting for its start (never started, or Reset) may
     * change it. The one rule - the settings form (the field disabled) and UpdateOnlineRoundSettingsHandler (a refusal).
     */
    public function detectionModeLockedAt(DateTimeImmutable $now): bool
    {
        return RoundClock::fromRound($this->round, $now)->isWaiting() === false;
    }

    /**
     * Every setting at once - call it with named arguments (UpdateOnlineRoundSettings)
     */
    public function changeSettings(
        OnlineDetectionMode $detectionMode,
        bool $videoEnabled,
        bool $participantsSeeEachOther,
        bool $studioEnabled,
        bool $recordingEnabled,
        OnlineUnfinishedMeasure $unfinishedMeasure,
        OnlineMissingPieceRule $missingPieceRule,
    ): void {
        $this->detectionMode = $detectionMode;
        $this->videoEnabled = $videoEnabled;
        $this->participantsSeeEachOther = $participantsSeeEachOther;
        $this->studioEnabled = $studioEnabled;
        $this->recordingEnabled = $recordingEnabled;
        $this->unfinishedMeasure = $unfinishedMeasure;
        $this->missingPieceRule = $missingPieceRule;
    }
}
