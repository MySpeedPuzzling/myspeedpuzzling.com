<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "New display" on the toolkit hub's Displays card and the displays page (D-displays-overlays.md §8.1).
 */
final class AddCompetitionDisplayFormData
{
    #[Assert\NotNull]
    public null|DisplayKind $kind = DisplayKind::Screen;

    #[Assert\NotBlank]
    #[Assert\Length(max: CompetitionDisplay::NAME_MAX_LENGTH)]
    public null|string $name = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: CompetitionDisplay::LOCALES)]
    public null|string $locale = null;

    public static function withLocale(string $requestLocale): self
    {
        $data = new self();
        $data->locale = in_array($requestLocale, CompetitionDisplay::LOCALES, true) ? $requestLocale : 'en';

        return $data;
    }
}
