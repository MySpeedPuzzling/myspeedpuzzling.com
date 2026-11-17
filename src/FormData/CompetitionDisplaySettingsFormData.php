<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Results\CompetitionDisplayOverview;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A display's settings (D-displays-overlays.md §8.1 `competition_display_settings`): name, language, round to follow.
 */
#[Assert\Callback('validate')]
final class CompetitionDisplaySettingsFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: CompetitionDisplay::NAME_MAX_LENGTH)]
    public null|string $name = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: CompetitionDisplay::LOCALES)]
    public null|string $locale = null;

    #[Assert\NotNull]
    public null|DisplayFollowMode $followMode = DisplayFollowMode::Current;

    public null|string $pinnedRoundId = null;

    /** @var list<string> */
    public array $roundIds = [];

    public static function fromDisplay(CompetitionDisplayOverview $display): self
    {
        $data = new self();
        $data->name = $display->name;
        $data->locale = $display->locale;
        $data->followMode = $display->followMode;
        $data->pinnedRoundId = $display->pinnedRoundId;
        $data->roundIds = $display->followRoundIds ?? [];

        return $data;
    }

    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->followMode === DisplayFollowMode::Pinned && ($this->pinnedRoundId === null || $this->pinnedRoundId === '')) {
            $context->buildViolation('competition_display.settings.pinned_round_required')
                ->setTranslationDomain('messages')
                ->atPath('pinnedRoundId')
                ->addViolation();
        }
    }
}
