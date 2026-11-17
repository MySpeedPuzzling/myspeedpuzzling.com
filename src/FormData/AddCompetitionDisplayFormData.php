<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * "New display" on the toolkit hub's Displays card and the displays page (D-displays-overlays.md §8.1).
 */
final class AddCompetitionDisplayFormData
{
    #[Assert\NotNull]
    public null|DisplayKind $kind = DisplayKind::Screen;

    // The design it starts from (D §6) - screen presets for screens, OBS presets for overlays, Blank for both
    #[Assert\NotNull]
    public null|DisplayPreset $preset = DisplayPreset::Blank;

    #[Assert\NotBlank]
    #[Assert\Length(max: CompetitionDisplay::NAME_MAX_LENGTH)]
    public null|string $name = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: CompetitionDisplay::LOCALES)]
    public null|string $locale = null;

    #[Assert\Callback]
    public function validatePresetFitsKind(ExecutionContextInterface $context): void
    {
        if ($this->preset !== null && $this->kind !== null && !$this->preset->fits($this->kind)) {
            $context->buildViolation('competition_display.form.preset_wrong_kind')
                ->setTranslationDomain('messages')
                ->atPath('preset')
                ->addViolation();
        }
    }

    public static function withLocale(string $requestLocale): self
    {
        $data = new self();
        $data->locale = in_array($requestLocale, CompetitionDisplay::LOCALES, true) ? $requestLocale : 'en';

        return $data;
    }
}
