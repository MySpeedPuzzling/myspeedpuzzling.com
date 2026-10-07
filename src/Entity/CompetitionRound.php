<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\CompetitionRoundsChanged;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;

#[Entity]
#[UniqueConstraint(name: 'competition_round_slug_unique', columns: ['competition_id', 'slug'])]
class CompetitionRound implements EntityWithEvents
{
    use HasEvents;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Competition $competition,
        #[Column]
        public string $name,
        #[Column]
        public int $minutesLimit,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $startsAt,
        /**
         * @var Collection<int, CompetitionRoundPuzzle>
         */
        #[OneToMany(targetEntity: CompetitionRoundPuzzle::class, mappedBy: 'round')]
        public Collection $roundPuzzles = new ArrayCollection(),
        #[Column(nullable: true)]
        public null|string $badgeBackgroundColor = null,
        #[Column(nullable: true)]
        public null|string $badgeTextColor = null,
        #[Column(enumType: RoundCategory::class, options: ['default' => 'solo'])]
        public RoundCategory $category = RoundCategory::Solo,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $stopwatchStartedAt = null,
        #[Column(nullable: true)]
        public null|string $stopwatchStatus = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $stopwatchStoppedAt = null,
        // Unique per competition, generated once on create and kept on rename, so shared result links survive
        #[Column(nullable: true)]
        public null|string $slug = null,
        // The organiser's own results page for this round, when they publish results per round
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $resultsLink = null,
        // The zone the organiser typed the start in, so it is edited and shown in that zone - see RoundTimezone
        #[Column(length: 64, nullable: true)]
        public null|string $timezone = null,
    ) {
    }

    /**
     * When a secret puzzle of this round with an automatic reveal is revealed - see RoundPuzzleReveal.
     */
    public function automaticRevealAt(): DateTimeImmutable
    {
        $revealAt = RoundPuzzleReveal::Automatic->revealAt($this->startsAt, null);
        assert($revealAt !== null);

        return $revealAt;
    }

    public function displayTimezone(): string
    {
        return RoundTimezone::resolve($this->timezone, $this->competition->locationCountryCode, $this->competition->series?->locationCountryCode);
    }

    /**
     * No zone of its own nor a country to take it from - displayTimezone() is only the fallback (RoundTimezone)
     */
    public function isTimezoneAssumed(): bool
    {
        return RoundTimezone::isAssumed($this->timezone, $this->competition->locationCountryCode, $this->competition->series?->locationCountryCode);
    }

    public function assignSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function edit(
        string $name,
        int $minutesLimit,
        DateTimeImmutable $startsAt,
        string $timezone,
        null|string $badgeBackgroundColor,
        null|string $badgeTextColor,
        RoundCategory $category = RoundCategory::Solo,
        null|string $resultsLink = null,
    ): void {
        if ($category !== $this->category) {
            $this->recordThat(new CompetitionRoundsChanged($this->competition->id));
        }

        $this->name = $name;
        $this->minutesLimit = $minutesLimit;
        $this->startsAt = $startsAt;
        $this->timezone = $timezone;
        $this->badgeBackgroundColor = $badgeBackgroundColor;
        $this->badgeTextColor = $badgeTextColor;
        $this->category = $category;
        $this->resultsLink = $resultsLink;
    }

    public function startStopwatch(DateTimeImmutable $startedAt): void
    {
        if (
            $this->stopwatchStatus === 'stopped'
            && $this->stopwatchStartedAt !== null
            && $this->stopwatchStoppedAt !== null
        ) {
            // Resume: shift startedAt forward by the pause duration so that
            // elapsed = now - startedAt continues from the paused value.
            $pauseSeconds = $startedAt->getTimestamp() - $this->stopwatchStoppedAt->getTimestamp();
            $this->stopwatchStartedAt = $this->stopwatchStartedAt->modify(
                sprintf('+%d seconds', $pauseSeconds),
            );
        } else {
            $this->stopwatchStartedAt = $startedAt;
        }
        $this->stopwatchStatus = 'running';
        $this->stopwatchStoppedAt = null;
    }

    public function stopStopwatch(DateTimeImmutable $stoppedAt): void
    {
        $this->stopwatchStatus = 'stopped';
        $this->stopwatchStoppedAt = $stoppedAt;
    }

    public function resetStopwatch(): void
    {
        $this->stopwatchStartedAt = null;
        $this->stopwatchStatus = null;
        $this->stopwatchStoppedAt = null;
    }
}
