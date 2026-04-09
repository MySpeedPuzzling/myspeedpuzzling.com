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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\RoundCategory;

#[Entity]
class CompetitionRound
{
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
    ) {
    }

    public function edit(
        string $name,
        int $minutesLimit,
        DateTimeImmutable $startsAt,
        null|string $badgeBackgroundColor,
        null|string $badgeTextColor,
        RoundCategory $category = RoundCategory::Solo,
    ): void {
        $this->name = $name;
        $this->minutesLimit = $minutesLimit;
        $this->startsAt = $startsAt;
        $this->badgeBackgroundColor = $badgeBackgroundColor;
        $this->badgeTextColor = $badgeTextColor;
        $this->category = $category;
    }

    public function startStopwatch(DateTimeImmutable $startedAt): void
    {
        $this->stopwatchStartedAt = $startedAt;
        $this->stopwatchStatus = 'running';
    }

    public function stopStopwatch(): void
    {
        $this->stopwatchStatus = 'stopped';
    }

    public function resetStopwatch(): void
    {
        $this->stopwatchStartedAt = null;
        $this->stopwatchStatus = null;
    }
}
