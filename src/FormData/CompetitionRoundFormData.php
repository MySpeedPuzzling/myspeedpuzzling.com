<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Validator\Constraints as Assert;

final class CompetitionRoundFormData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 250)]
        public null|string $name = null,
        #[Assert\Positive]
        public null|int $minutesLimit = null,
        public null|DateTimeImmutable $startsAt = null,
        #[Assert\Length(max: 250)]
        public null|string $badgeBackgroundColor = '#fe696a',
        #[Assert\Length(max: 250)]
        public null|string $badgeTextColor = '#ffffff',
        public RoundCategory $category = RoundCategory::Solo,
    ) {
    }

    public static function fromCompetitionRound(CompetitionRound $round): self
    {
        $data = new self();
        $data->name = $round->name;
        $data->minutesLimit = $round->minutesLimit;
        $data->startsAt = $round->startsAt;
        $data->badgeBackgroundColor = $round->badgeBackgroundColor;
        $data->badgeTextColor = $round->badgeTextColor;
        $data->category = $round->category;

        return $data;
    }
}
