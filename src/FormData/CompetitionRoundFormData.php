<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use Symfony\Component\Validator\Constraints as Assert;

final class CompetitionRoundFormData
{
    public function __construct(
        #[Assert\NotBlank]
        public null|string $name = null,
        #[Assert\Positive]
        public null|int $minutesLimit = null,
        #[Assert\NotNull]
        public null|DateTimeImmutable $startsAt = null,
        public null|string $badgeBackgroundColor = '#fe696a',
        public null|string $badgeTextColor = '#ffffff',
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

        return $data;
    }
}
